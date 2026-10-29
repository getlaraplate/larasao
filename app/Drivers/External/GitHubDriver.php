<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Modules\SAO\Drivers\Contracts\BlameCapability;
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
use Modules\SAO\Drivers\Support\ReadsExternalPayloads;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Override;
use Throwable;

/**
 * The `issues` driver for GitHub, over the REST API. Authentication is a bearer
 * token; the binding's `remoteIdentifier` is `owner/repo`. Pagination follows
 * the `Link` header's `rel="next"`. GitHub has no native priority, and its
 * status is the issue `state` (open/closed) — `translateStatus` maps that
 * through the binding map. Pull requests share the issues endpoint and are
 * filtered out.
 */
final readonly class GitHubDriver implements BlameCapability, DriverInterface, IssuesCapability, ReleasesCapability, VcsCapability
{
    use ReadsExternalPayloads;

    private const int DEFAULT_PAGE_SIZE = 30;

    #[Override]
    public function key(): string
    {
        return 'github';
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
            new ConfigurationField('token', 'string', 'GitHub personal access or app token', required: true, secret: true),
            new ConfigurationField('page_size', 'integer', 'Issues fetched per page', required: false),
        ]);
    }

    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        if ($context->baseUrl === null) {
            return HealthCheckResult::unhealthy('No base URL configured for the GitHub connection.');
        }

        try {
            $response = $this->client($context)->get('/rate_limit');
        } catch (Throwable $exception) {
            return HealthCheckResult::unhealthy($exception->getMessage());
        }

        return $response->successful()
            ? HealthCheckResult::healthy()
            : HealthCheckResult::unhealthy("GitHub returned HTTP {$response->status()}.");
    }

    /**
     * @return array<string, mixed>|null
     */
    #[Override]
    public function lookup(BindingContext $context, string $remoteId): ?array
    {
        $response = $this->connection($context)->get($this->repoPath($context) . "/issues/{$remoteId}");

        if ($response->status() === 404) {
            return null;
        }

        $issue = $response->json();

        return is_array($issue) && isset($issue['number']) ? $this->normalize($context, $issue)->toArray() : null;
    }

    #[Override]
    public function list(BindingContext $context, ?string $cursor = null): Page
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->connection($context)->get($this->repoPath($context) . '/issues', [
            'state' => 'all',
            'per_page' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json() ?? [];

        $items = [];

        foreach ($rows as $row) {
            // The issues endpoint also returns pull requests; they carry a
            // `pull_request` object and are not tickets.
            if (! isset($row['pull_request'])) {
                $items[] = $this->normalize($context, $row)->toArray();
            }
        }

        return new Page(
            array_values($items),
            nextCursor: $this->nextPageFromLink($response->header('Link')),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    public function create(BindingContext $context, array $attributes): array
    {
        $response = $this->connection($context)->post($this->repoPath($context) . '/issues', $this->toGitHubIssue($attributes));

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
        $response = $this->connection($context)->patch($this->repoPath($context) . "/issues/{$remoteId}", $this->toGitHubIssue($attributes));

        /** @var array<string, mixed> $issue */
        $issue = $response->json() ?? [];

        return $this->normalize($context, $issue)->toArray();
    }

    #[Override]
    public function comment(BindingContext $context, string $remoteId, string $body): void
    {
        $this->connection($context)->post($this->repoPath($context) . "/issues/{$remoteId}/comments", [
            'body' => $body,
        ]);
    }

    /**
     * @param  array<string, string>  $statusMap  Remote state (open/closed) → canonical category.
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

        $response = $this->connection($context)->get($this->repoPath($context) . '/commits', [
            'sha' => $range,
            'per_page' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json() ?? [];

        $items = array_map(fn (array $commit): array => [
            'sha' => self::stringOr($commit['sha'] ?? ''),
            'message' => $this->commitMessage($commit),
            'url' => self::stringOrNull($commit['html_url']),
            'author' => $this->authorLogin($commit),
            'author_name' => $this->commitAuthorField($commit, 'name'),
            'author_email' => $this->commitAuthorField($commit, 'email'),
        ], $rows);

        return new Page(
            array_values($items),
            nextCursor: $this->nextPageFromLink($response->header('Link')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function compare(BindingContext $context, string $base, string $head): array
    {
        $response = $this->connection($context)->get($this->repoPath($context) . "/compare/{$base}...{$head}");

        /** @var array<string, mixed> $payload */
        return $response->json() ?? [];
    }

    #[Override]
    public function fileAtRef(BindingContext $context, string $path, string $ref): ?string
    {
        $response = $this->connection($context)->get($this->repoPath($context) . "/contents/{$path}", [
            'ref' => $ref,
        ]);

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
        $response = $this->connection($context)->post($this->repoPath($context) . '/pulls', [
            'title' => $attributes['title'] ?? null,
            'head' => $attributes['source'] ?? null,
            'base' => $attributes['target'] ?? null,
            'body' => $attributes['body'] ?? null,
        ]);

        /** @var array<string, mixed> $pull */
        $pull = $response->json() ?? [];

        return [
            'remote_id' => self::stringOrNull($pull['number']),
            'url' => self::stringOrNull($pull['html_url']),
            'raw' => $pull,
        ];
    }

    /**
     * Line ownership for a file at a ref, via the GraphQL blame API (REST has no
     * blame endpoint). Ranges are collapsed to a per-author line tally, keyed by
     * the account login when present, else the git author email.
     *
     * @return list<array{author: ?string, author_email: ?string, lines: int}>
     */
    #[Override]
    public function blame(BindingContext $context, string $path, string $ref): array
    {
        [$owner, $name] = array_pad(explode('/', (string) $context->remoteIdentifier, 2), 2, '');

        $response = $this->connection($context)->post('/graphql', [
            'query' => $this->blameQuery(),
            'variables' => ['owner' => $owner, 'name' => $name, 'expr' => $ref, 'path' => $path],
        ]);

        $ranges = $response->json('data.repository.object.blame.ranges');

        if (! is_array($ranges)) {
            return [];
        }

        /** @var array<string, array{author: ?string, author_email: ?string, lines: int}> $tally */
        $tally = [];

        foreach ($ranges as $range) {
            if (! is_array($range)) {
                continue;
            }

            $lines = max(0, (int) ($range['endingLine'] ?? 0) - (int) ($range['startingLine'] ?? 0) + 1);
            $author = is_array($range['commit']['author'] ?? null) ? $range['commit']['author'] : [];
            $login = is_array($author['user'] ?? null) ? self::stringOrNull($author['user']['login'] ?? null) : null;
            $email = self::stringOrNull($author['email']);
            $key = $login ?? $email;

            if ($key === null || $key === '') {
                continue;
            }

            if (! isset($tally[$key])) {
                $tally[$key] = ['author' => $login, 'author_email' => $email, 'lines' => 0];
            }

            $tally[$key]['lines'] += $lines;
        }

        return array_values($tally);
    }

    #[Override]
    public function tags(BindingContext $context, ?string $cursor = null): Page
    {
        $page = $cursor === null ? 1 : (int) $cursor;

        $response = $this->connection($context)->get($this->repoPath($context) . '/tags', [
            'per_page' => $this->pageSize($context),
            'page' => $page,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $response->json() ?? [];

        $items = array_map(fn (array $tag): array => [
            'tag' => self::stringOr($tag['name'] ?? ''),
            'sha' => $this->tagSha($tag),
        ], $rows);

        return new Page(
            array_values($items),
            nextCursor: $this->nextPageFromLink($response->header('Link')),
        );
    }

    /**
     * Compares `$sha` (base) to `$ref` (head): `ahead` or `identical` means the ref
     * contains the commit, `behind` or `diverged` that it does not. GitHub answers 404
     * (or 422 for a ref it cannot resolve) for an unknown side; with any other error,
     * a network failure or a status it does not document, the answer is null.
     * A sha that is not hexadecimal is not asked about.
     */
    #[Override]
    public function containsCommit(BindingContext $context, string $sha, string $ref): ?bool
    {
        if (preg_match('/\A[0-9a-f]+\z/i', $sha) !== 1 || $ref === '') {
            return null;
        }

        try {
            $response = $this->connection($context)->get(
                $this->repoPath($context) . '/compare/' . rawurlencode($sha) . '...' . rawurlencode($ref),
            );
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        return match ($response->json('status')) {
            'ahead', 'identical' => true,
            'behind', 'diverged' => false,
            default => null,
        };
    }

    /**
     * Best-effort over the first page of tags: the first tag that contains the commit
     * ({@see containsCommit()} is true; a tag it cannot tell about is skipped).
     * GitHub exposes no single "tags containing commit" endpoint; recent tags are the
     * useful set.
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
     * @param  array<string, mixed>  $commit
     */
    private function commitMessage(array $commit): ?string
    {
        $inner = $commit['commit'] ?? null;

        return is_array($inner) ? self::stringOrNull($inner['message'] ?? null) : null;
    }

    /**
     * The GitHub account that authored the commit — the handle CODEOWNERS and
     * an identity map speak in. Null when the commit is not linked to an account.
     *
     * @param  array<string, mixed>  $commit
     */
    private function authorLogin(array $commit): ?string
    {
        $author = $commit['author'] ?? null;

        return is_array($author) ? self::stringOrNull($author['login'] ?? null) : null;
    }

    /**
     * A field of the git author record (name/email), which is present even when
     * the commit is not linked to a GitHub account.
     *
     * @param  array<string, mixed>  $commit
     */
    private function commitAuthorField(array $commit, string $field): ?string
    {
        $inner = $commit['commit'] ?? null;
        $author = is_array($inner) ? ($inner['author'] ?? null) : null;

        return is_array($author) && isset($author[$field]) ? (string) $author[$field] : null;
    }

    /**
     * @param  array<string, mixed>  $tag
     */
    private function tagSha(array $tag): ?string
    {
        $commit = $tag['commit'] ?? null;

        return is_array($commit) ? self::stringOrNull($commit['sha'] ?? null) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function toGitHubIssue(array $attributes): array
    {
        $issue = [];

        if (array_key_exists('title', $attributes)) {
            $issue['title'] = $attributes['title'];
        }

        if (array_key_exists('body', $attributes)) {
            $issue['body'] = $attributes['body'];
        } elseif (array_key_exists('description', $attributes)) {
            $issue['body'] = $attributes['description'];
        }

        if (array_key_exists('state', $attributes)) {
            $issue['state'] = $attributes['state'];
        }

        return $issue;
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function normalize(BindingContext $context, array $issue): NormalizedIssue
    {
        $number = self::stringOr($issue['number'] ?? '');
        $repo = $context->remoteIdentifier;

        return new NormalizedIssue(
            remoteId: $number,
            title: self::stringOr($issue['title'] ?? ''),
            key: $repo !== null && $number !== '' ? "{$repo}#{$number}" : null,
            body: self::stringOrNull($issue['body']),
            remoteStatus: self::stringOrNull($issue['state']),
            remotePriority: null,
            assignee: $this->assigneeLogin($issue),
            url: self::stringOrNull($issue['html_url']),
            createdAt: self::stringOrNull($issue['created_at']),
            updatedAt: self::stringOrNull($issue['updated_at']),
        );
    }

    /**
     * @param  array<string, mixed>  $issue
     */
    private function assigneeLogin(array $issue): ?string
    {
        $assignee = $issue['assignee'] ?? null;

        return is_array($assignee) ? self::stringOrNull($assignee['login'] ?? null) : null;
    }

    private function nextPageFromLink(?string $link): ?string
    {
        if ($link === null) {
            return null;
        }

        if (preg_match('/<[^>]*[?&]page=(\d+)[^>]*>;\s*rel="next"/', $link, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function repoPath(BindingContext $context): string
    {
        return '/repos/' . (string) $context->remoteIdentifier;
    }

    private function blameQuery(): string
    {
        return <<<'GRAPHQL'
        query ($owner: String!, $name: String!, $expr: String!, $path: String!) {
          repository(owner: $owner, name: $name) {
            object(expression: $expr) {
              ... on Commit {
                blame(path: $path) {
                  ranges {
                    startingLine
                    endingLine
                    commit { author { name email user { login } } }
                  }
                }
              }
            }
          }
        }
        GRAPHQL;
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
            ->withToken($token)
            ->withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
            ]);
    }
}
