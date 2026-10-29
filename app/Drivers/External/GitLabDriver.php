<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\Contracts\IssuesCapability;
use Modules\SAO\Drivers\Contracts\ReleasesCapability;
use Modules\SAO\Drivers\Contracts\VcsCapability;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConfigurationField;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Drivers\Support\DriverConfigurationSchema;
use Modules\SAO\Drivers\Support\HealthCheckResult;
use Modules\SAO\Drivers\Support\NormalizedIssue;
use Modules\SAO\Drivers\Support\Page;
use Modules\SAO\Drivers\Support\ReadsCommitShas;
use Modules\SAO\Drivers\Support\ReadsExternalPayloads;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Override;
use Throwable;

/**
 * The `issues` driver for GitLab, over the REST v4 API. Authentication is the
 * `PRIVATE-TOKEN` header; the binding's `remoteIdentifier` is the project id (a
 * numeric id or a URL-encoded path). Issues are addressed by their project-scoped
 * `iid`. Pagination follows the `X-Next-Page` response header. The status is the
 * issue `state` (opened/closed), mapped through the binding map.
 */
final readonly class GitLabDriver implements DriverInterface, IssuesCapability, ReleasesCapability, VcsCapability
{
    use ReadsCommitShas;
    use ReadsExternalPayloads;

    private const int DEFAULT_PAGE_SIZE = 20;

    #[Override]
    public function key(): string
    {
        return 'gitlab';
    }

    /**
     * @return list<Capability>
     */
    #[Override]
    public function capabilities(): array
    {
        return [Capability::Issues, Capability::Vcs, Capability::Releases];
    }

    /**
     * @return list<IngestMode>
     */
    #[Override]
    public function ingestModes(): array
    {
        return [IngestMode::Pull];
    }

    #[Override]
    public function configurationSchema(): DriverConfigurationSchema
    {
        return new DriverConfigurationSchema([
            new ConfigurationField('token', 'string', 'GitLab personal or project access token', required: true, secret: true),
            new ConfigurationField('page_size', 'integer', 'Issues fetched per page', required: false),
        ]);
    }

    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        if ($context->baseUrl === null) {
            return HealthCheckResult::unhealthy('No base URL configured for the GitLab connection.');
        }

        try {
            $response = $this->client($context)->get('/api/v4/version');
        } catch (Throwable $exception) {
            return HealthCheckResult::unhealthy($exception->getMessage());
        }

        return $response->successful()
            ? HealthCheckResult::healthy()
            : HealthCheckResult::unhealthy("GitLab returned HTTP {$response->status()}.");
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Override]
    public function lookup(BindingContext $context, string $remoteId): ?array
    {
        $response = $this->connection($context)->get($this->issuesPath($context) . "/{$remoteId}");

        if ($response->status() === 404) {
            return null;
        }

        $issue = $response->json();

        return is_array($issue) && isset($issue['iid']) ? $this->normalize($context, $issue)->toArray() : null;
    }

    #[Override]
    public function list(BindingContext $context, ?string $cursor = null): Page
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->connection($context)->get($this->issuesPath($context), [
            'per_page' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json() ?? [];

        $items = array_map(
            fn (array $issue): array => $this->normalize($context, $issue)->toArray(),
            $rows,
        );

        $nextPage = $response->header('X-Next-Page');

        return new Page(
            array_values($items),
            nextCursor: $nextPage === null || $nextPage === '' ? null : $nextPage,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    public function create(BindingContext $context, array $attributes): array
    {
        $response = $this->connection($context)->post($this->issuesPath($context), $this->toGitLabIssue($attributes));

        /** @var array<string, mixed> $issue */
        $issue = $response->json() ?? [];

        return $this->normalize($context, $issue)->toArray();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    public function update(BindingContext $context, string $remoteId, array $attributes): array
    {
        $response = $this->connection($context)->put($this->issuesPath($context) . "/{$remoteId}", $this->toGitLabIssue($attributes));

        /** @var array<string, mixed> $issue */
        $issue = $response->json() ?? [];

        return $this->normalize($context, $issue)->toArray();
    }

    #[Override]
    public function comment(BindingContext $context, string $remoteId, string $body): void
    {
        $this->connection($context)->post($this->issuesPath($context) . "/{$remoteId}/notes", [
            'body' => $body,
        ]);
    }

    /**
     * @param  array<string, string>  $statusMap  Remote state (opened/closed) → canonical category.
     */
    #[Override]
    public function translateStatus(array $statusMap, string $remoteStatus): ?string
    {
        return $statusMap[$remoteStatus] ?? null;
    }

    #[Override]
    public function commits(BindingContext $context, string $range, ?string $cursor = null): Page
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->connection($context)->get($this->repositoryPath($context) . '/commits', [
            'ref_name' => $range,
            'per_page' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json() ?? [];

        $items = array_map(static fn (array $commit): array => [
            'sha' => self::stringOr($commit['id'] ?? ''),
            'message' => self::stringOrNull($commit['message']),
            'url' => self::stringOrNull($commit['web_url']),
            // GitLab's commits API exposes the git author name/email but not the
            // account username, so the handle stays null here.
            'author' => null,
            'author_name' => self::stringOrNull($commit['author_name']),
            'author_email' => self::stringOrNull($commit['author_email']),
        ], $rows);

        $nextPage = $response->header('X-Next-Page');

        return new Page(
            array_values($items),
            nextCursor: $nextPage === null || $nextPage === '' ? null : $nextPage,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function compare(BindingContext $context, string $base, string $head): array
    {
        $response = $this->connection($context)->get($this->repositoryPath($context) . '/compare', [
            'from' => $base,
            'to' => $head,
        ]);

        /** @var array<string, mixed> $payload */
        return $response->json() ?? [];
    }

    #[Override]
    public function fileAtRef(BindingContext $context, string $path, string $ref): ?string
    {
        $response = $this->connection($context)->get(
            $this->repositoryPath($context) . '/files/' . rawurlencode($path),
            ['ref' => $ref],
        );

        if (! $response->successful()) {
            return null;
        }

        $content = $response->json('content');

        if (! is_string($content)) {
            return null;
        }

        $decoded = base64_decode(str_replace("\n", '', $content), true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    public function openPullRequest(BindingContext $context, array $attributes): array
    {
        $response = $this->connection($context)->post('/api/v4/projects/' . (string) $context->remoteIdentifier . '/merge_requests', [
            'source_branch' => $attributes['source'] ?? null,
            'target_branch' => $attributes['target'] ?? null,
            'title' => $attributes['title'] ?? null,
            'description' => $attributes['body'] ?? null,
        ]);

        /** @var array<string, mixed> $mr */
        $mr = $response->json() ?? [];

        return [
            'remote_id' => self::stringOrNull($mr['iid']),
            'url' => self::stringOrNull($mr['web_url']),
            'raw' => $mr,
        ];
    }

    #[Override]
    public function tags(BindingContext $context, ?string $cursor = null): Page
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->connection($context)->get($this->repositoryPath($context) . '/tags', [
            'per_page' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json() ?? [];

        $items = array_map(static fn (array $tag): array => [
            'tag' => self::stringOr($tag['name'] ?? ''),
            'sha' => self::stringOrNull($tag['commit']['id']),
        ], $rows);

        $nextPage = $response->header('X-Next-Page');

        return new Page(
            array_values($items),
            nextCursor: $nextPage === null || $nextPage === '' ? null : $nextPage,
        );
    }

    /**
     * The merge base of `$sha` and `$ref` is `$sha` itself when the ref contains the
     * commit (the repository merge-base endpoint, `id` in the answer). GitLab answers
     * 404 for an unknown commit or ref, and also when two histories share no base, so
     * those, any other error, a network failure and an answer without an `id` are null.
     * A sha shorter than seven characters or not hexadecimal is not asked about; a
     * shorter sha matches the full merge base by prefix.
     */
    #[Override]
    public function containsCommit(BindingContext $context, string $sha, string $ref): ?bool
    {
        if (! self::isUsableSha($sha) || $ref === '') {
            return null;
        }

        // `refs[]` is spelled out: a query array would be sent as `refs[0]`, which GitLab
        // reads as a hash rather than the list of refs.
        $url = $this->repositoryPath($context) . '/merge_base?refs%5B%5D=' . rawurlencode($sha) . '&refs%5B%5D=' . rawurlencode($ref);

        try {
            $response = $this->connection($context)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        $mergeBase = $response->successful() ? self::stringOrNull($response->json('id')) : null;

        return $mergeBase === null ? null : self::sameCommit($sha, $mergeBase);
    }

    /**
     * Best-effort over the first page of tags: the first tag that contains the commit
     * ({@see containsCommit()} is true; a tag it cannot tell about is skipped).
     * GitLab exposes no single "tags containing commit" endpoint.
     */
    #[Override]
    public function firstTagContaining(BindingContext $context, string $commitSha): ?string
    {
        foreach ($this->tags($context)->items as $tag) {
            $name = self::stringOr($tag['tag'] ?? '');

            if ($name !== '' && $this->containsCommit($context, $commitSha, $name) === true) {
                return $name;
            }
        }

        return null;
    }

    private function repositoryPath(BindingContext $context): string
    {
        return '/api/v4/projects/' . (string) $context->remoteIdentifier . '/repository';
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function toGitLabIssue(array $attributes): array
    {
        $issue = [];

        if (array_key_exists('title', $attributes)) {
            $issue['title'] = $attributes['title'];
        }

        if (array_key_exists('body', $attributes)) {
            $issue['description'] = $attributes['body'];
        } elseif (array_key_exists('description', $attributes)) {
            $issue['description'] = $attributes['description'];
        }

        return $issue;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function normalize(BindingContext $context, array $issue): NormalizedIssue
    {
        $iid = self::stringOr($issue['iid'] ?? '');
        $project = $context->remoteIdentifier;

        return new NormalizedIssue(
            remoteId: $iid,
            title: self::stringOr($issue['title'] ?? ''),
            key: $project !== null && $iid !== '' ? "{$project}#{$iid}" : null,
            body: self::stringOrNull($issue['description']),
            remoteStatus: self::stringOrNull($issue['state']),
            remotePriority: null,
            assignee: $this->assigneeUsername($issue),
            url: self::stringOrNull($issue['web_url']),
            createdAt: self::stringOrNull($issue['created_at']),
            updatedAt: self::stringOrNull($issue['updated_at']),
        );
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function assigneeUsername(array $issue): ?string
    {
        $assignee = $issue['assignee'] ?? null;

        return is_array($assignee) ? self::stringOrNull($assignee['username'] ?? null) : null;
    }

    private function issuesPath(BindingContext $context): string
    {
        return '/api/v4/projects/' . (string) $context->remoteIdentifier . '/issues';
    }

    private function pageSize(BindingContext $context): int
    {
        $configured = $context->config['page_size'] ?? null;

        return $configured === null ? self::DEFAULT_PAGE_SIZE : max(1, (int) $configured);
    }

    private function connection(BindingContext $context): PendingRequest
    {
        return $this->client($context->connection);
    }

    private function client(ConnectionContext $context): PendingRequest
    {
        $token = self::stringOr($context->credentials['token'] ?? '');

        return Http::baseUrl((string) $context->baseUrl)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['PRIVATE-TOKEN' => $token]);
    }
}
