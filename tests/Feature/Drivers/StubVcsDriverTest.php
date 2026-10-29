<?php

declare(strict_types=1);

use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Tests\Support\Conformance\VcsConformance;
use Modules\SAO\Tests\Support\Drivers\StubVcsDriver;

function stubVcsContext(): BindingContext
{
    return new BindingContext(new ConnectionContext(baseUrl: 'https://stub.test', credentials: []), remoteIdentifier: 'acme/widgets');
}

test('the stub vcs driver passes the contains-commit conformance when it answers true', function (): void {
    VcsConformance::assertContainsCommit(new StubVcsDriver(containsCommit: true), stubVcsContext());
});

test('the stub vcs driver answers the configured tri-state and counts every call', function (?bool $answer): void {
    $stub = new StubVcsDriver(containsCommit: $answer);

    expect($stub->containsCommitCallCount())->toBe(0)
        ->and($stub->containsCommit(stubVcsContext(), 'abc1234', 'main'))->toBe($answer)
        ->and($stub->containsCommit(stubVcsContext(), 'def5678', 'v1.0.0'))->toBe($answer)
        ->and($stub->containsCommitCallCount())->toBe(2)
        ->and($stub->containsCommitCalls())->toBe([
            ['sha' => 'abc1234', 'ref' => 'main'],
            ['sha' => 'def5678', 'ref' => 'v1.0.0'],
        ]);
})->with([true, false, null]);
