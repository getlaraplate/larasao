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
 * The `issues` driver for Bitbucket Cloud, over the REST 2.0 API. Authentication
 * is Basic (username + app password); the binding's `remoteIdentifier` is
 * `workspace/repo_slug`. Pagination follows the body's `next` link. The status
 * is the Bitbucket issue `state` and the priority its `priority`, both mapped
 * through the binding maps rather than hardcoded.
 */
final readonly class BitbucketDriver implements DriverInterface, IssuesCapability, ReleasesCapability, VcsCapability
{
    use ReadsCommitShas;
    use ReadsExternalPayloads;

    private const int DEFAULT_PAGE_SIZE = 20;

    #[Override]
    public function key(): string
    {
        return 'bitbucket';
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
            new ConfigurationField('username', 'string', 'Bitbucket username', required: true),
            new ConfigurationField('token', 'string', 'Bitbucket app password', required: true, secret: true),
            new ConfigurationField('page_size', 'integer', 'Issues fetched per page', required: false),
        ]);
    }

    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        if ($context->baseUrl === null) {
            return HealthCheckResult::unhealthy('No base URL configured for the Bitbucket connection.');
        }

        try {
            $response = $this->client($context)->get('/user');
        } catch (Throwable $exception) {
            return HealthCheckResult::unhealthy($exception->getMessage());
        }

        return $response->successful()
            ? HealthCheckResult::healthy()
            : HealthCheckResult::unhealthy("Bitbucket returned HTTP {$response->status()}.");
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

        return is_array($issue) && isset($issue['id']) ? $this->normalize($context, $issue)->toArray() : null;
    }

    #[Override]
    public function list(BindingContext $context, ?string $cursor = null): Page
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->connection($context)->get($this->issuesPath($context), [
            'pagelen' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $values */
        $values = $response->json('values', []);

        $items = array_map(
            fn (array $issue): array => $this->normalize($context, $issue)->toArray(),
            $values,
        );

        // Bitbucket returns a `next` link when more pages exist; the page number
        // stays a valid cursor because the page size is fixed per call.
        $hasNext = $response->json('next') !== null;

        return new Page(
            array_values($items),
            nextCursor: $hasNext ? (string) ($page + 1) : null,
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    public function create(BindingContext $context, array $attributes): array
    {
        $response = $this->connection($context)->post($this->issuesPath($context), $this->toBitbucketIssue($attributes));

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
        $response = $this->connection($context)->put($this->issuesPath($context) . "/{$remoteId}", $this->toBitbucketIssue($attributes));

        /** @var array<string, mixed> $issue */
        $issue = $response->json() ?? [];

        return $this->normalize($context, $issue)->toArray();
    }

    #[Override]
    public function comment(BindingContext $context, string $remoteId, string $body): void
    {
        $this->connection($context)->post($this->issuesPath($context) . "/{$remoteId}/comments", [
            'content' => ['raw' => $body],
        ]);
    }

    /**
     * @param  array<string, string>  $statusMap  Remote state → canonical category.
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

        $response = $this->connection($context)->get($this->repoPath($context) . "/commits/{$range}", [
            'pagelen' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $values */
        $values = $response->json('values', []);

        $items = array_map(fn (array $commit): array => [
            'sha' => self::stringOr($commit['hash'] ?? ''),
            'message' => self::stringOrNull($commit['message']),
            'url' => self::stringOrNull($commit['links']['html']['href']),
            'author' => $this->authorNickname($commit),
            'author_name' => $this->authorName($commit),
            'author_email' => $this->authorEmail($commit),
        ], $values);

        $hasNext = $response->json('next') !== null;

        return new Page(
            array_values($items),
            nextCursor: $hasNext ? (string) ($page + 1) : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function compare(BindingContext $context, string $base, string $head): array
    {
        // Bitbucket's diffstat spec is `{head}..{base}` — the changes to move
        // from base to head.
        $response = $this->connection($context)->get($this->repoPath($context) . "/diffstat/{$head}..{$base}");

        /** @var array<string, mixed> $payload */
        return $response->json() ?? [];
    }

    #[Override]
    public function fileAtRef(BindingContext $context, string $path, string $ref): ?string
    {
        // The src endpoint returns the raw file body, not JSON.
        $response = $this->connection($context)->get($this->repoPath($context) . "/src/{$ref}/{$path}");

        return $response->successful() ? $response->body() : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    public function openPullRequest(BindingContext $context, array $attributes): array
    {
        $response = $this->connection($context)->post($this->repoPath($context) . '/pullrequests', [
            'title' => $attributes['title'] ?? null,
            'source' => ['branch' => ['name' => $attributes['source'] ?? null]],
            'destination' => ['branch' => ['name' => $attributes['target'] ?? null]],
        ]);

        /** @var array<string, mixed> $pr */
        $pr = $response->json() ?? [];

        return [
            'remote_id' => self::stringOrNull($pr['id']),
            'url' => self::stringOrNull($pr['links']['html']['href']),
            'raw' => $pr,
        ];
    }

    #[Override]
    public function tags(BindingContext $context, ?string $cursor = null): Page
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->connection($context)->get($this->repoPath($context) . '/refs/tags', [
            'pagelen' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $values */
        $values = $response->json('values', []);

        $items = array_map(static fn (array $tag): array => [
            'tag' => self::stringOr($tag['name'] ?? ''),
            'sha' => self::stringOrNull($tag['target']['hash']),
        ], $values);

        $hasNext = $response->json('next') !== null;

        return new Page(
            array_values($items),
            nextCursor: $hasNext ? (string) ($page + 1) : null,
        );
    }

    /**
     * The merge base of `$sha` and `$ref` (the `merge-base/{sha}..{ref}` endpoint,
     * `hash` in the answer) is `$sha` itself when the ref contains the commit.
     * Bitbucket answers 404 for an unknown repository, commit or ref; that, any other
     * error, a network failure and an answer without a `hash` are null. A sha shorter
     * than seven characters or not hexadecimal is not asked about; a shorter sha
     * matches the full merge base by prefix.
     */
    #[Override]
    public function containsCommit(BindingContext $context, string $sha, string $ref): ?bool
    {
        if (! self::isUsableSha($sha) || $ref === '') {
            return null;
        }

        try {
            $response = $this->connection($context)->get(
                $this->repoPath($context) . '/merge-base/' . rawurlencode($sha) . '..' . rawurlencode($ref),
            );
        } catch (ConnectionException) {
            return null;
        }

        $mergeBase = $response->successful() ? self::stringOrNull($response->json('hash')) : null;

        return $mergeBase === null ? null : self::sameCommit($sha, $mergeBase);
    }

    /**
     * Best-effort over the first page of tags: the first tag that contains the commit
     * ({@see containsCommit()} is true; a tag it cannot tell about is skipped).
     * Bitbucket exposes no single "tags containing commit" endpoint.
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

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function toBitbucketIssue(array $attributes): array
    {
        $issue = [];

        if (array_key_exists('title', $attributes)) {
            $issue['title'] = $attributes['title'];
        }

        if (array_key_exists('body', $attributes)) {
            $issue['content'] = ['raw' => $attributes['body']];
        } elseif (array_key_exists('description', $attributes)) {
            $issue['content'] = ['raw' => $attributes['description']];
        }

        return $issue;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function normalize(BindingContext $context, array $issue): NormalizedIssue
    {
        $id = self::stringOr($issue['id'] ?? '');
        $repo = $context->remoteIdentifier;

        return new NormalizedIssue(
            remoteId: $id,
            title: self::stringOr($issue['title'] ?? ''),
            key: $repo !== null && $id !== '' ? "{$repo}#{$id}" : null,
            body: $this->contentRaw($issue),
            remoteStatus: self::stringOrNull($issue['state']),
            remotePriority: self::stringOrNull($issue['priority']),
            assignee: $this->assigneeName($issue),
            url: $this->htmlLink($issue),
            createdAt: self::stringOrNull($issue['created_on']),
            updatedAt: self::stringOrNull($issue['updated_on']),
        );
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function contentRaw(array $issue): ?string
    {
        $content = $issue['content'] ?? null;

        return is_array($content) ? self::stringOrNull($content['raw'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function assigneeName(array $issue): ?string
    {
        $assignee = $issue['assignee'] ?? null;

        if (! is_array($assignee)) {
            return null;
        }

        return isset($assignee['display_name'])
            ? self::stringOr($assignee['display_name'])
            : (self::stringOrNull($assignee['nickname']));
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function htmlLink(array $issue): ?string
    {
        $links = $issue['links'] ?? null;

        if (! is_array($links) || ! isset($links['html']) || ! is_array($links['html'])) {
            return null;
        }

        return self::stringOrNull($links['html']['href']);
    }

    /**
     * The Bitbucket account nickname that authored the commit — the handle an
     * identity map speaks in. Null when the commit is not linked to an account.
     *
     * @param  array<string, mixed>  $commit
     */
    private function authorNickname(array $commit): ?string
    {
        $user = $commit['author']['user'] ?? null;

        return is_array($user) ? self::stringOrNull($user['nickname'] ?? null) : null;
    }

    /**
     * The author display name: the linked account's, else the name parsed from
     * the raw `Name <email>` git author string.
     *
     * @param  array<string, mixed>  $commit
     */
    private function authorName(array $commit): ?string
    {
        $user = $commit['author']['user'] ?? null;

        if (is_array($user) && isset($user['display_name'])) {
            return self::stringOr($user['display_name']);
        }

        $raw = self::stringOr($commit['author']['raw'] ?? '');
        $name = mb_trim((string) preg_replace('/<[^>]*>/', '', $raw));

        return $name === '' ? null : $name;
    }

    /**
     * The author email, parsed from the raw `Name <email>` git author string.
     *
     * @param  array<string, mixed>  $commit
     */
    private function authorEmail(array $commit): ?string
    {
        $raw = self::stringOr($commit['author']['raw'] ?? '');

        return preg_match('/<([^>]+)>/', $raw, $matches) === 1 ? $matches[1] : null;
    }

    private function repoPath(BindingContext $context): string
    {
        return '/repositories/' . (string) $context->remoteIdentifier;
    }

    private function issuesPath(BindingContext $context): string
    {
        return $this->repoPath($context) . '/issues';
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
        $username = self::stringOr($context->credentials['username'] ?? '');
        $token = self::stringOr($context->credentials['token'] ?? '');

        return Http::baseUrl((string) $context->baseUrl)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($username, $token);
    }
}
