<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\SAO\Drivers\External\BitbucketDriver;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Modules\SAO\Tests\Support\Conformance\IssuesConformance;
use Modules\SAO\Tests\Support\Conformance\ReleasesConformance;
use Modules\SAO\Tests\Support\Conformance\VcsConformance;

/**
 * A network-free, stateful stand-in for the Bitbucket Cloud REST 2.0 API. The
 * list responses carry a `next` link, which is how Bitbucket signals more pages.
 */
function fakeBitbucket(): void
{
    $store = [
        1 => bitbucketIssue(1, 'First', 'new'),
        2 => bitbucketIssue(2, 'Second', 'open'),
        3 => bitbucketIssue(3, 'Third', 'new'),
        4 => bitbucketIssue(4, 'Fourth', 'new'),
        5 => bitbucketIssue(5, 'Fifth', 'new'),
    ];
    $nextId = 6;

    Http::fake(function (Request $request) use (&$store, &$nextId) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $method = $request->method();

        if ($path === '/2.0/user') {
            return Http::response(['account_id' => 'me']);
        }

        if ($path === '/2.0/repositories/acme/widgets/issues' && $method === 'GET') {
            $page = (int) ($query['page'] ?? 1);
            $pagelen = (int) ($query['pagelen'] ?? 10);
            $all = array_values($store);
            $slice = array_slice($all, ($page - 1) * $pagelen, $pagelen);
            $hasMore = $page * $pagelen < count($all);
            $payload = ['values' => array_values($slice), 'size' => count($all), 'page' => $page, 'pagelen' => $pagelen];

            if ($hasMore) {
                $payload['next'] = 'https://api.bitbucket.org/2.0/repositories/acme/widgets/issues?page=' . ($page + 1);
            }

            return Http::response($payload);
        }

        if ($path === '/2.0/repositories/acme/widgets/issues' && $method === 'POST') {
            $body = $request->data();
            $id = $nextId++;
            $store[$id] = bitbucketIssue($id, (string) ($body['title'] ?? ''), 'new');

            return Http::response($store[$id], 201);
        }

        if (preg_match('#^/2.0/repositories/acme/widgets/issues/(\d+)$#', $path, $m) === 1) {
            $id = (int) $m[1];

            if ($method === 'GET') {
                return isset($store[$id])
                    ? Http::response($store[$id])
                    : Http::response(['type' => 'error'], 404);
            }

            if ($method === 'PUT') {
                $body = $request->data();

                if (isset($store[$id]) && array_key_exists('title', $body)) {
                    $store[$id]['title'] = $body['title'];
                }

                return Http::response($store[$id] ?? ['id' => $id]);
            }
        }

        if (preg_match('#^/2.0/repositories/acme/widgets/issues/(\d+)/comments$#', $path, $m) === 1 && $method === 'POST') {
            return Http::response(['id' => 1], 201);
        }

        if (str_starts_with($path, '/2.0/repositories/acme/widgets/commits/') && $method === 'GET') {
            $page = (int) ($query['page'] ?? 1);
            $pagelen = (int) ($query['pagelen'] ?? 10);
            $all = array_map(static fn (int $n): array => [
                'hash' => 'c' . $n,
                'message' => 'commit ' . $n,
                'author' => ['raw' => 'Grace Hopper <grace@example.com>', 'user' => ['nickname' => 'ghopper', 'display_name' => 'Grace Hopper']],
                'links' => ['html' => ['href' => "https://bitbucket.org/acme/widgets/commits/c{$n}"]],
            ], range(1, 5));
            $slice = array_slice($all, ($page - 1) * $pagelen, $pagelen);
            $payload = ['values' => array_values($slice), 'pagelen' => $pagelen, 'page' => $page];

            if ($page * $pagelen < count($all)) {
                $payload['next'] = 'https://api.bitbucket.org/2.0/repositories/acme/widgets/commits/main?page=' . ($page + 1);
            }

            return Http::response($payload);
        }

        if (str_starts_with($path, '/2.0/repositories/acme/widgets/diffstat/')) {
            return Http::response(['values' => [['status' => 'modified']], 'size' => 1]);
        }

        if (str_starts_with($path, '/2.0/repositories/acme/widgets/merge-base/')) {
            // The merge base of a commit and a later ref is the commit itself, full length.
            $spec = rawurldecode(mb_substr($path, mb_strlen('/2.0/repositories/acme/widgets/merge-base/')));

            return Http::response(['hash' => mb_str_pad(explode('..', $spec)[0], 40, '0')]);
        }

        if (str_starts_with($path, '/2.0/repositories/acme/widgets/src/')) {
            $rest = mb_substr($path, mb_strlen('/2.0/repositories/acme/widgets/src/'));
            $file = mb_substr($rest, (int) mb_strpos($rest, '/') + 1);

            return $file === 'README.md'
                ? Http::response("# Widgets\n")
                : Http::response(['type' => 'error'], 404);
        }

        if ($path === '/2.0/repositories/acme/widgets/pullrequests' && $method === 'POST') {
            return Http::response(['id' => 9, 'links' => ['html' => ['href' => 'https://bitbucket.org/acme/widgets/pull-requests/9']]], 201);
        }

        if ($path === '/2.0/repositories/acme/widgets/refs/tags' && $method === 'GET') {
            $page = (int) ($query['page'] ?? 1);
            $pagelen = (int) ($query['pagelen'] ?? 10);
            $all = array_map(static fn (string $t): array => [
                'name' => $t,
                'target' => ['hash' => 'sha-' . $t],
            ], ['v1.0.0', 'v1.1.0', 'v2.0.0']);
            $slice = array_slice($all, ($page - 1) * $pagelen, $pagelen);
            $payload = ['values' => array_values($slice), 'pagelen' => $pagelen, 'page' => $page];

            if ($page * $pagelen < count($all)) {
                $payload['next'] = 'https://api.bitbucket.org/2.0/repositories/acme/widgets/refs/tags?page=' . ($page + 1);
            }

            return Http::response($payload);
        }

        return Http::response(['type' => 'error'], 500);
    });
}

/**
 * @return array<string, mixed>
 */
function bitbucketIssue(int $id, string $title, string $state): array
{
    return [
        'id' => $id,
        'title' => $title,
        'content' => ['raw' => 'body of #' . $id],
        'state' => $state,
        'priority' => 'major',
        'assignee' => ['display_name' => 'Bit Bucket'],
        'created_on' => '2026-08-01T10:00:00Z',
        'updated_on' => '2026-08-02T11:00:00Z',
        'links' => ['html' => ['href' => "https://bitbucket.org/acme/widgets/issues/{$id}"]],
    ];
}

function bitbucketContext(): BindingContext
{
    return new BindingContext(
        new ConnectionContext(
            baseUrl: 'https://api.bitbucket.org/2.0',
            credentials: ['username' => 'bot', 'token' => 'app-password'],
        ),
        remoteIdentifier: 'acme/widgets',
        config: ['page_size' => 2],
    );
}

test('the bitbucket driver declares issues, vcs and releases with pull ingest', function (): void {
    $driver = new BitbucketDriver;

    expect($driver->key())->toBe('bitbucket')
        ->and($driver->capabilities())->toBe([Capability::Issues, Capability::Vcs, Capability::Releases])
        ->and($driver->ingestModes())->toBe([IngestMode::Pull]);
});

test('the bitbucket driver passes the vcs and releases conformance suites', function (): void {
    fakeBitbucket();

    VcsConformance::assert(new BitbucketDriver, bitbucketContext());
    ReleasesConformance::assert(new BitbucketDriver, bitbucketContext());
});

test('the bitbucket driver passes the issues conformance suite', function (): void {
    fakeBitbucket();

    IssuesConformance::assert(new BitbucketDriver, bitbucketContext());
});

test('the bitbucket driver authenticates with basic auth', function (): void {
    fakeBitbucket();

    (new BitbucketDriver)->list(bitbucketContext());

    Http::assertSent(static function (Request $request): bool {
        $expected = 'Basic ' . base64_encode('bot:app-password');

        return $request->hasHeader('Authorization', $expected);
    });
});

test('it normalizes a bitbucket commit author into the canonical shape', function (): void {
    fakeBitbucket();

    $commit = (new BitbucketDriver)->commits(bitbucketContext(), 'main')->items[0];

    expect($commit['author'])->toBe('ghopper')
        ->and($commit['author_name'])->toBe('Grace Hopper')
        ->and($commit['author_email'])->toBe('grace@example.com');
});

test('it normalizes a bitbucket issue into the canonical shape', function (): void {
    fakeBitbucket();

    $issue = (new BitbucketDriver)->lookup(bitbucketContext(), '2');

    expect($issue['remote_id'])->toBe('2')
        ->and($issue['key'])->toBe('acme/widgets#2')
        ->and($issue['title'])->toBe('Second')
        ->and($issue['body'])->toBe('body of #2')
        ->and($issue['remote_status'])->toBe('open')
        ->and($issue['remote_priority'])->toBe('major')
        ->and($issue['assignee'])->toBe('Bit Bucket')
        ->and($issue['url'])->toBe('https://bitbucket.org/acme/widgets/issues/2');
});

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeBitbucketMergeBase(array|string $body, int $status = 200): void
{
    Http::fake(['api.bitbucket.org/2.0/repositories/acme/widgets/merge-base/*' => Http::response($body, $status)]);
}

test('bitbucket tells that a ref contains a commit when the merge base is the commit', function (): void {
    $sha = str_repeat('a', 40);
    fakeBitbucketMergeBase(['hash' => $sha]);

    expect((new BitbucketDriver)->containsCommit(bitbucketContext(), $sha, 'main'))->toBeTrue();

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET'
        && str_ends_with((string) parse_url($request->url(), PHP_URL_PATH), "/merge-base/{$sha}..main"));
});

test('bitbucket tells that a ref does not contain a commit when the merge base is another commit', function (): void {
    fakeBitbucketMergeBase(['hash' => str_repeat('b', 40)]);

    expect((new BitbucketDriver)->containsCommit(bitbucketContext(), str_repeat('a', 40), 'main'))->toBeFalse();
});

test('bitbucket matches a short sha against the full merge base, in any letter case', function (): void {
    fakeBitbucketMergeBase(['hash' => 'ABC1234' . str_repeat('0', 33)]);

    expect((new BitbucketDriver)->containsCommit(bitbucketContext(), 'abc1234', 'main'))->toBeTrue()
        ->and((new BitbucketDriver)->containsCommit(bitbucketContext(), 'ABC1234', 'main'))->toBeTrue()
        ->and((new BitbucketDriver)->containsCommit(bitbucketContext(), 'abc1235', 'main'))->toBeFalse();
});

test('bitbucket cannot tell for an unknown commit, a server error or an unexpected shape', function (int $httpStatus, array|string $body): void {
    fakeBitbucketMergeBase($body, $httpStatus);

    expect((new BitbucketDriver)->containsCommit(bitbucketContext(), 'abc1234', 'main'))->toBeNull();
})->with([
    'unknown commit' => [404, ['type' => 'error']],
    'forbidden' => [403, ['type' => 'error']],
    'server error' => [500, ['type' => 'error']],
    'no hash' => [200, ['type' => 'commit']],
    'array hash' => [200, ['hash' => ['x']]],
    'not json' => [200, '<html></html>'],
]);

test('bitbucket cannot tell when the network fails', function (): void {
    Http::fake(['api.bitbucket.org/*' => Http::failedConnection()]);

    expect((new BitbucketDriver)->containsCommit(bitbucketContext(), 'abc1234', 'main'))->toBeNull();
});

test('bitbucket asks nothing for a sha shorter than seven characters or not hexadecimal', function (string $sha): void {
    Http::fake();

    expect((new BitbucketDriver)->containsCommit(bitbucketContext(), $sha, 'main'))->toBeNull();

    Http::assertNothingSent();
})->with(['', 'abc123', 'not-a-sha', 'abc123g']);

test('bitbucket url-encodes a ref that contains a slash', function (): void {
    fakeBitbucketMergeBase(['hash' => str_repeat('a', 40)]);

    (new BitbucketDriver)->containsCommit(bitbucketContext(), str_repeat('a', 40), 'release/1.0');

    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), '..release%2F1.0'));
});

test('bitbucket first tag containing a commit skips a diverged tag and an unknown one', function (): void {
    $sha = str_repeat('a', 40);
    Http::fake([
        'api.bitbucket.org/2.0/repositories/acme/widgets/refs/tags*' => Http::response([
            'values' => [
                ['name' => 'v1.0.0', 'target' => ['hash' => 's1']],
                ['name' => 'v1.1.0', 'target' => ['hash' => 's2']],
                ['name' => 'v2.0.0', 'target' => ['hash' => 's3']],
            ],
        ]),
        'api.bitbucket.org/2.0/repositories/acme/widgets/merge-base/*' => static function (Request $request) use ($sha) {
            $spec = (string) basename((string) parse_url($request->url(), PHP_URL_PATH));

            return match (explode('..', $spec)[1] ?? null) {
                'v1.0.0' => Http::response(['hash' => str_repeat('b', 40)]),
                'v1.1.0' => Http::response(['type' => 'error'], 404),
                default => Http::response(['hash' => $sha]),
            };
        },
    ]);

    expect((new BitbucketDriver)->firstTagContaining(bitbucketContext(), $sha))->toBe('v2.0.0');
});
