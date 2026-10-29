<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\SAO\Drivers\External\GitLabDriver;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Modules\SAO\Tests\Support\Conformance\IssuesConformance;
use Modules\SAO\Tests\Support\Conformance\ReleasesConformance;
use Modules\SAO\Tests\Support\Conformance\VcsConformance;

/**
 * A network-free, stateful stand-in for the GitLab REST v4 API. Pagination is
 * signalled with the `X-Next-Page` response header, as GitLab does.
 */
function fakeGitLab(): void
{
    $store = [
        1 => gitlabIssue(1, 'First', 'opened'),
        2 => gitlabIssue(2, 'Second', 'opened'),
        3 => gitlabIssue(3, 'Third', 'opened'),
        4 => gitlabIssue(4, 'Fourth', 'opened'),
        5 => gitlabIssue(5, 'Fifth', 'opened'),
    ];
    $nextIid = 6;

    Http::fake(function (Request $request) use (&$store, &$nextIid) {
        $path = parse_url($request->url(), PHP_URL_PATH) ?? '';
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $method = $request->method();

        if ($path === '/api/v4/version') {
            return Http::response(['version' => '17.0.0']);
        }

        if ($path === '/api/v4/projects/42/issues' && $method === 'GET') {
            $page = (int) ($query['page'] ?? 1);
            $perPage = (int) ($query['per_page'] ?? 20);
            $all = array_values($store);
            $slice = array_slice($all, ($page - 1) * $perPage, $perPage);
            $hasMore = $page * $perPage < count($all);

            return Http::response(array_values($slice), 200, [
                'X-Next-Page' => $hasMore ? (string) ($page + 1) : '',
                'X-Total' => (string) count($all),
            ]);
        }

        if ($path === '/api/v4/projects/42/issues' && $method === 'POST') {
            $body = $request->data();
            $iid = $nextIid++;
            $store[$iid] = gitlabIssue($iid, (string) ($body['title'] ?? ''), 'opened');

            return Http::response($store[$iid], 201);
        }

        if (preg_match('#^/api/v4/projects/42/issues/(\d+)$#', $path, $m) === 1) {
            $iid = (int) $m[1];

            if ($method === 'GET') {
                return isset($store[$iid])
                    ? Http::response($store[$iid])
                    : Http::response(['message' => '404 Not found'], 404);
            }

            if ($method === 'PUT') {
                $body = $request->data();

                if (isset($store[$iid]) && array_key_exists('title', $body)) {
                    $store[$iid]['title'] = $body['title'];
                }

                return Http::response($store[$iid] ?? ['iid' => $iid]);
            }
        }

        if (preg_match('#^/api/v4/projects/42/issues/(\d+)/notes$#', $path, $m) === 1 && $method === 'POST') {
            return Http::response(['id' => 1], 201);
        }

        if ($path === '/api/v4/projects/42/repository/commits' && $method === 'GET') {
            $page = (int) ($query['page'] ?? 1);
            $perPage = (int) ($query['per_page'] ?? 20);
            $all = array_map(static fn (int $n): array => [
                'id' => 'c' . $n,
                'message' => 'commit ' . $n,
                'web_url' => "https://gitlab.com/acme/widgets/-/commit/c{$n}",
                'author_name' => 'Ada Lovelace',
                'author_email' => 'ada@example.com',
            ], range(1, 5));
            $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

            return Http::response(array_values($slice), 200, [
                'X-Next-Page' => $page * $perPage < count($all) ? (string) ($page + 1) : '',
            ]);
        }

        if ($path === '/api/v4/projects/42/repository/compare' && $method === 'GET') {
            return Http::response(['commits' => [['id' => 'c1']], 'diffs' => []]);
        }

        if ($path === '/api/v4/projects/42/repository/merge_base' && $method === 'GET') {
            // The merge base of a commit and a later ref is the commit itself, full length.
            return Http::response(['id' => mb_str_pad((string) ($query['refs'][0] ?? ''), 40, '0')]);
        }

        if (str_starts_with($path, '/api/v4/projects/42/repository/files/')) {
            $file = rawurldecode(mb_substr($path, mb_strlen('/api/v4/projects/42/repository/files/')));

            return $file === 'README.md'
                ? Http::response(['content' => base64_encode("# Widgets\n"), 'encoding' => 'base64'])
                : Http::response(['message' => '404 File Not Found'], 404);
        }

        if ($path === '/api/v4/projects/42/merge_requests' && $method === 'POST') {
            return Http::response(['iid' => 7, 'web_url' => 'https://gitlab.com/acme/widgets/-/merge_requests/7'], 201);
        }

        if ($path === '/api/v4/projects/42/repository/tags' && $method === 'GET') {
            $page = (int) ($query['page'] ?? 1);
            $perPage = (int) ($query['per_page'] ?? 20);
            $all = array_map(static fn (string $t): array => [
                'name' => $t,
                'commit' => ['id' => 'sha-' . $t],
            ], ['v1.0.0', 'v1.1.0', 'v2.0.0']);
            $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

            return Http::response(array_values($slice), 200, [
                'X-Next-Page' => $page * $perPage < count($all) ? (string) ($page + 1) : '',
            ]);
        }

        return Http::response(['message' => 'Unhandled'], 500);
    });
}

/**
 * @return array<string, mixed>
 */
function gitlabIssue(int $iid, string $title, string $state): array
{
    return [
        'id' => 9000 + $iid,
        'iid' => $iid,
        'title' => $title,
        'description' => 'body of #' . $iid,
        'state' => $state,
        'web_url' => "https://gitlab.com/acme/widgets/-/issues/{$iid}",
        'assignee' => ['username' => 'gluser'],
        'created_at' => '2026-08-01T10:00:00Z',
        'updated_at' => '2026-08-02T11:00:00Z',
    ];
}

function gitlabContext(): BindingContext
{
    return new BindingContext(
        new ConnectionContext(baseUrl: 'https://gitlab.com', credentials: ['token' => 'glpat-secret']),
        remoteIdentifier: '42',
        config: ['page_size' => 2],
    );
}

test('the gitlab driver declares issues, vcs and releases with pull ingest', function (): void {
    $driver = new GitLabDriver;

    expect($driver->key())->toBe('gitlab')
        ->and($driver->capabilities())->toBe([Capability::Issues, Capability::Vcs, Capability::Releases])
        ->and($driver->ingestModes())->toBe([IngestMode::Pull]);
});

test('the gitlab driver passes the vcs and releases conformance suites', function (): void {
    fakeGitLab();

    VcsConformance::assert(new GitLabDriver, gitlabContext());
    ReleasesConformance::assert(new GitLabDriver, gitlabContext());
});

test('the gitlab driver passes the issues conformance suite', function (): void {
    fakeGitLab();

    IssuesConformance::assert(new GitLabDriver, gitlabContext());
});

test('the gitlab driver authenticates with the PRIVATE-TOKEN header', function (): void {
    fakeGitLab();

    (new GitLabDriver)->list(gitlabContext());

    Http::assertSent(static fn (Request $request): bool => $request->hasHeader('PRIVATE-TOKEN', 'glpat-secret'));
});

test('it normalizes a gitlab commit author into the canonical shape', function (): void {
    fakeGitLab();

    $commit = (new GitLabDriver)->commits(gitlabContext(), 'main')->items[0];

    // GitLab's commits API carries no account username, so the handle is null.
    expect($commit['author'])->toBeNull()
        ->and($commit['author_name'])->toBe('Ada Lovelace')
        ->and($commit['author_email'])->toBe('ada@example.com');
});

test('it normalizes a gitlab issue into the canonical shape', function (): void {
    fakeGitLab();

    $issue = (new GitLabDriver)->lookup(gitlabContext(), '2');

    expect($issue['remote_id'])->toBe('2')
        ->and($issue['key'])->toBe('42#2')
        ->and($issue['title'])->toBe('Second')
        ->and($issue['remote_status'])->toBe('opened')
        ->and($issue['assignee'])->toBe('gluser')
        ->and($issue['url'])->toBe('https://gitlab.com/acme/widgets/-/issues/2');
});

/**
 * @param  array<string, mixed>|string  $body
 */
function fakeGitLabMergeBase(array|string $body, int $status = 200): void
{
    Http::fake(['gitlab.com/api/v4/projects/42/repository/merge_base*' => Http::response($body, $status)]);
}

test('gitlab tells that a ref contains a commit when the merge base is the commit', function (): void {
    $sha = str_repeat('a', 40);
    fakeGitLabMergeBase(['id' => $sha]);

    expect((new GitLabDriver)->containsCommit(gitlabContext(), $sha, 'main'))->toBeTrue();

    Http::assertSent(static function (Request $request) use ($sha): bool {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET' && ($query['refs'] ?? null) === [$sha, 'main'];
    });
});

test('gitlab tells that a ref does not contain a commit when the merge base is another commit', function (): void {
    fakeGitLabMergeBase(['id' => str_repeat('b', 40)]);

    expect((new GitLabDriver)->containsCommit(gitlabContext(), str_repeat('a', 40), 'main'))->toBeFalse();
});

test('gitlab matches a short sha against the full merge base, in any letter case', function (): void {
    fakeGitLabMergeBase(['id' => 'ABC1234' . str_repeat('0', 33)]);

    expect((new GitLabDriver)->containsCommit(gitlabContext(), 'abc1234', 'main'))->toBeTrue()
        ->and((new GitLabDriver)->containsCommit(gitlabContext(), 'ABC1234', 'main'))->toBeTrue()
        ->and((new GitLabDriver)->containsCommit(gitlabContext(), 'abc1235', 'main'))->toBeFalse();
});

test('gitlab cannot tell for an unknown commit, a server error or an unexpected shape', function (int $httpStatus, array|string $body): void {
    fakeGitLabMergeBase($body, $httpStatus);

    expect((new GitLabDriver)->containsCommit(gitlabContext(), 'abc1234', 'main'))->toBeNull();
})->with([
    'unknown commit' => [404, ['message' => '404 Commit Not Found']],
    'bad request' => [400, ['message' => 'bad']],
    'server error' => [500, ['message' => 'boom']],
    'no id' => [200, ['title' => 'x']],
    'array id' => [200, ['id' => ['x']]],
    'not json' => [200, '<html></html>'],
]);

test('gitlab cannot tell when the network fails', function (): void {
    Http::fake(['gitlab.com/*' => Http::failedConnection()]);

    expect((new GitLabDriver)->containsCommit(gitlabContext(), 'abc1234', 'main'))->toBeNull();
});

test('gitlab asks nothing for a sha shorter than seven characters or not hexadecimal', function (string $sha): void {
    Http::fake();

    expect((new GitLabDriver)->containsCommit(gitlabContext(), $sha, 'main'))->toBeNull();

    Http::assertNothingSent();
})->with(['', 'abc123', 'not-a-sha', 'abc123g']);

test('gitlab url-encodes a ref that contains a slash', function (): void {
    fakeGitLabMergeBase(['id' => str_repeat('a', 40)]);

    (new GitLabDriver)->containsCommit(gitlabContext(), str_repeat('a', 40), 'release/1.0');

    Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'release%2F1.0'));
});

test('gitlab first tag containing a commit skips a diverged tag and an unknown one', function (): void {
    $sha = str_repeat('a', 40);
    Http::fake([
        'gitlab.com/api/v4/projects/42/repository/tags*' => Http::response([
            ['name' => 'v1.0.0', 'commit' => ['id' => 's1']],
            ['name' => 'v1.1.0', 'commit' => ['id' => 's2']],
            ['name' => 'v2.0.0', 'commit' => ['id' => 's3']],
        ]),
        'gitlab.com/api/v4/projects/42/repository/merge_base*' => static function (Request $request) use ($sha) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return match ($query['refs'][1] ?? null) {
                'v1.0.0' => Http::response(['id' => str_repeat('b', 40)]),
                'v1.1.0' => Http::response(['message' => '404'], 404),
                default => Http::response(['id' => $sha]),
            };
        },
    ]);

    expect((new GitLabDriver)->firstTagContaining(gitlabContext(), $sha))->toBe('v2.0.0');
});
