<?php

declare(strict_types=1);

use Modules\SAO\Drivers\External\GitHubPullRequestDriver;
use Modules\SAO\Drivers\External\WebhookCodeDriver;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Enums\ChangeRefType;
use Modules\SAO\Tests\Support\Conformance\CodeConformance;

function codeContext(string $secret = 'shared'): BindingContext
{
    return new BindingContext(new ConnectionContext(baseUrl: null, credentials: ['secret' => $secret]));
}

test('the generic webhook code driver passes the code conformance battery', function (): void {
    $payload = (string) json_encode([
        'identifier' => '42',
        'text' => 'Fixes SAO-1',
        'type' => 'pull_request',
        'merged_at' => '2026-08-23T10:00:00Z',
    ]);

    CodeConformance::assert(new WebhookCodeDriver, codeContext(), $payload, ['X-Code-Token' => 'shared']);
});

test('the generic webhook code driver unpacks a batch of events', function (): void {
    $payload = (string) json_encode([
        'events' => [
            ['identifier' => 'sha1', 'type' => 'commit', 'text' => 'refs SAO-1'],
            ['identifier' => '9', 'text' => 'closes SAO-2'],
        ],
    ]);

    $refs = (new WebhookCodeDriver)->unpack(codeContext(), $payload);

    expect($refs)->toHaveCount(2)
        ->and($refs[0]->type)->toBe(ChangeRefType::Commit)
        ->and($refs[1]->type)->toBe(ChangeRefType::PullRequest);
});

test('the github pull-request driver passes the code conformance battery for a merged PR', function (): void {
    $payload = githubPrPayload(merged: true);
    $headers = ['X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $payload, 'shared')];

    CodeConformance::assert(new GitHubPullRequestDriver, codeContext(), $payload, $headers);
});

test('the github pull-request driver ignores an unmerged pull request', function (): void {
    $refs = (new GitHubPullRequestDriver)->unpack(codeContext(), githubPrPayload(merged: false));

    expect($refs)->toBe([]);
});

test('the github pull-request driver maps a merged PR into a change reference', function (): void {
    $refs = (new GitHubPullRequestDriver)->unpack(codeContext(), githubPrPayload(merged: true));

    expect($refs)->toHaveCount(1)
        ->and($refs[0]->type)->toBe(ChangeRefType::PullRequest)
        ->and($refs[0]->identifier)->toBe('7')
        ->and($refs[0]->text)->toContain('Fixes SAO-1')
        ->and($refs[0]->mergedAt)->not->toBeNull()
        ->and($refs[0]->baseRef)->toBe('main')
        ->and($refs[0]->headRef)->toBe('fix/null-guard');
});

test('the github pull-request driver carries the merge commit sha of a merged PR', function (): void {
    $refs = (new GitHubPullRequestDriver)->unpack(codeContext(), githubPrPayload(merged: true));

    expect($refs[0]->commitSha)->toBe('c0ffee1234');
});

function githubPrPayload(bool $merged): string
{
    return (string) json_encode([
        'action' => $merged ? 'closed' : 'opened',
        'pull_request' => [
            'number' => 7,
            'merged' => $merged,
            'merged_at' => $merged ? '2026-08-23T10:00:00Z' : null,
            'merge_commit_sha' => 'c0ffee1234',
            'title' => 'Fixes SAO-1',
            'body' => 'A null guard for the parser.',
            'html_url' => 'https://github.com/acme/app/pull/7',
            'base' => ['ref' => 'main'],
            'head' => ['ref' => 'fix/null-guard'],
        ],
    ]);
}
