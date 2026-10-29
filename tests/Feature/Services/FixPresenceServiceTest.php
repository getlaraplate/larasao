<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ChangeRefRelation;
use Modules\SAO\Jobs\CheckFixPresenceJob;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\FixPresence;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Services\FixPresenceService;
use Modules\SAO\Tests\Support\Drivers\FailingContainsCommitVcsDriver;
use Modules\SAO\Tests\Support\Drivers\StubVcsDriver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // The saved hooks would run the check as soon as the fixtures are written;
    // faking the queue keeps each test's VCS calls to the ones it makes itself.
    Queue::fake();
});

/**
 * Registers `$driver` and binds it to `$project` as a `vcs` binding.
 */
function fp_bind_vcs(Project $project, DriverInterface $driver, string $remoteIdentifier = 'acme/app'): ProjectBinding
{
    app(DriverRegistry::class)->register($driver);

    $connection = Connection::factory()->create([
        'driver_key' => $driver->key(),
        'capabilities' => [Capability::Vcs],
        'base_url' => null,
        'credential' => ['token' => 'x'],
    ]);

    return ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Vcs,
        'remote_identifier' => $remoteIdentifier,
    ]);
}

function fp_stub(?bool $answer, string $key = 'stub-vcs'): StubVcsDriver
{
    return new StubVcsDriver(key: $key, containsCommit: $answer);
}

function fp_fix(Project $project, string $sha, ChangeRefRelation $relation = ChangeRefRelation::Fixes): ChangeRef
{
    $ticket = Ticket::factory()->forProject($project)->create();

    return ChangeRef::factory()->create([
        'ticket_id' => $ticket->getKey(),
        'relation' => $relation,
        'commit_sha' => $sha,
    ]);
}

function fp_environment(Project $project, string $name, ?string $commit): Environment
{
    return Environment::factory()->create([
        'project_id' => $project->getKey(),
        'name' => $name,
        'current_commit' => $commit,
    ]);
}

test('checking an environment writes one presence per fix with the vcs answer', function (): void {
    $project = Project::factory()->create();
    $driver = fp_stub(true);
    fp_bind_vcs($project, $driver);
    $first = fp_fix($project, 'aaaaaaa1');
    $second = fp_fix($project, 'bbbbbbb2');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    $written = app(FixPresenceService::class)->checkEnvironment($environment);

    expect($written)->toBe(2)
        ->and(FixPresence::query()->count())->toBe(2)
        ->and(FixPresence::query()->where('change_ref_id', $first->id)->sole()->contained)->toBeTrue()
        ->and(FixPresence::query()->where('change_ref_id', $second->id)->sole()->deployed_commit)->toBe('deadbeef00')
        ->and(FixPresence::query()->where('change_ref_id', $second->id)->sole()->checked_at)->not->toBeNull()
        ->and($driver->containsCommitCalls())->toEqualCanonicalizing([
            ['sha' => 'aaaaaaa1', 'ref' => 'deadbeef00'],
            ['sha' => 'bbbbbbb2', 'ref' => 'deadbeef00'],
        ]);
});

test('a negative answer is stored as not contained', function (): void {
    $project = Project::factory()->create();
    fp_bind_vcs($project, fp_stub(false));
    fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    app(FixPresenceService::class)->checkEnvironment($environment);

    expect(FixPresence::query()->sole()->contained)->toBeFalse();
});

test('an unknown answer is stored as null and asked again on the next check', function (): void {
    $project = Project::factory()->create();
    $driver = fp_stub(null);
    fp_bind_vcs($project, $driver);
    fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    app(FixPresenceService::class)->checkEnvironment($environment);
    $written = app(FixPresenceService::class)->checkEnvironment($environment);

    expect($written)->toBe(1)
        ->and($driver->containsCommitCallCount())->toBe(2)
        ->and(FixPresence::query()->count())->toBe(1)
        ->and(FixPresence::query()->sole()->contained)->toBeNull();
});

test('a stored null presence is overwritten by a later answer', function (): void {
    $project = Project::factory()->create();
    fp_bind_vcs($project, fp_stub(true));
    $changeRef = fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');
    FixPresence::factory()->unknown()->create([
        'change_ref_id' => $changeRef->id,
        'environment_id' => $environment->id,
        'deployed_commit' => 'deadbeef00',
    ]);

    app(FixPresenceService::class)->checkEnvironment($environment);

    expect(FixPresence::query()->sole()->contained)->toBeTrue();
});

test('a new deployed commit adds a presence and keeps the earlier one', function (): void {
    $project = Project::factory()->create();
    fp_bind_vcs($project, fp_stub(false));
    $changeRef = fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    app(FixPresenceService::class)->checkEnvironment($environment);
    $earlier = FixPresence::query()->sole();

    $environment->update(['current_commit' => 'cafebabe11']);
    app(FixPresenceService::class)->checkEnvironment($environment);

    expect(FixPresence::query()->where('change_ref_id', $changeRef->id)->orderBy('id')->pluck('deployed_commit')->all())
        ->toBe(['deadbeef00', 'cafebabe11'])
        ->and($earlier->fresh()->contained)->toBeFalse()
        ->and($earlier->fresh()->checked_at->equalTo($earlier->checked_at))->toBeTrue();
});

test('an unchanged deployed commit with a known answer makes no vcs call', function (): void {
    $project = Project::factory()->create();
    $driver = fp_stub(true);
    fp_bind_vcs($project, $driver);
    fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    app(FixPresenceService::class)->checkEnvironment($environment);
    $written = app(FixPresenceService::class)->checkEnvironment($environment);

    expect($written)->toBe(0)
        ->and($driver->containsCommitCallCount())->toBe(1);
});

test('mentions, fixes without a commit and other projects are not checked', function (): void {
    $project = Project::factory()->create();
    $driver = fp_stub(true);
    fp_bind_vcs($project, $driver);
    fp_fix($project, 'aaaaaaa1', ChangeRefRelation::Mentions);
    ChangeRef::factory()->create(['ticket_id' => Ticket::factory()->forProject($project), 'commit_sha' => null]);
    fp_fix(Project::factory()->create(), 'bbbbbbb2');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    expect(app(FixPresenceService::class)->checkEnvironment($environment))->toBe(0)
        ->and($driver->containsCommitCallCount())->toBe(0)
        ->and(FixPresence::query()->count())->toBe(0);
});

test('checking a change ref covers the environments of its project that report a commit', function (): void {
    $project = Project::factory()->create();
    $driver = fp_stub(true);
    fp_bind_vcs($project, $driver);
    $changeRef = fp_fix($project, 'aaaaaaa1');
    $production = fp_environment($project, 'production', 'deadbeef00');
    $staging = fp_environment($project, 'staging', 'cafebabe11');
    fp_environment($project, 'qa', null);
    fp_environment(Project::factory()->create(), 'production', 'f00df00d22');

    $written = app(FixPresenceService::class)->checkChangeRef($changeRef);

    expect($written)->toBe(2)
        ->and(FixPresence::query()->orderBy('environment_id')->pluck('environment_id')->all())
        ->toBe([$production->id, $staging->id]);
});

test('a change ref that only mentions its ticket is not checked', function (): void {
    $project = Project::factory()->create();
    $driver = fp_stub(true);
    fp_bind_vcs($project, $driver);
    $mention = fp_fix($project, 'aaaaaaa1', ChangeRefRelation::Mentions);
    fp_environment($project, 'production', 'deadbeef00');

    expect(app(FixPresenceService::class)->checkChangeRef($mention))->toBe(0)
        ->and($driver->containsCommitCallCount())->toBe(0);
});

test('a project without a vcs binding writes nothing', function (): void {
    $project = Project::factory()->create();
    fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    expect(app(FixPresenceService::class)->checkEnvironment($environment))->toBe(0)
        ->and(FixPresence::query()->count())->toBe(0);
});

test('the first vcs binding of the project answers', function (): void {
    $project = Project::factory()->create();
    $first = fp_stub(true, 'first-vcs');
    $second = fp_stub(false, 'second-vcs');
    fp_bind_vcs($project, $first, 'acme/one');
    fp_bind_vcs($project, $second, 'acme/two');
    fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    app(FixPresenceService::class)->checkEnvironment($environment);

    expect($first->containsCommitCallCount())->toBe(1)
        ->and($second->containsCommitCallCount())->toBe(0)
        ->and(FixPresence::query()->sole()->contained)->toBeTrue();
});

test('a driver failure on one pair is logged and stored as null while the others are answered', function (): void {
    Log::spy();
    $project = Project::factory()->create();
    fp_bind_vcs($project, new FailingContainsCommitVcsDriver('aaaaaaa1', answer: true));
    $broken = fp_fix($project, 'aaaaaaa1');
    $fine = fp_fix($project, 'bbbbbbb2');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    $written = app(FixPresenceService::class)->checkEnvironment($environment);

    expect($written)->toBe(2)
        ->and(FixPresence::query()->where('change_ref_id', $broken->id)->sole()->contained)->toBeNull()
        ->and(FixPresence::query()->where('change_ref_id', $fine->id)->sole()->contained)->toBeTrue();

    Log::shouldHaveReceived('warning')->withArgs(static fn (string $message): bool => $message === 'sao.fix_presence.check_failed')->once();
});

test('a changed deployed commit queues a check of the environment', function (): void {
    $environment = fp_environment(Project::factory()->create(), 'production', 'deadbeef00');
    Queue::fake();

    $environment->update(['current_commit' => 'cafebabe11']);

    Queue::assertPushed(CheckFixPresenceJob::class, static fn (CheckFixPresenceJob $job): bool => $job->environmentId === $environment->id && $job->changeRefId === null);
});

test('a new environment that reports a commit queues a check', function (): void {
    $environment = fp_environment(Project::factory()->create(), 'production', 'deadbeef00');

    Queue::assertPushed(CheckFixPresenceJob::class, static fn (CheckFixPresenceJob $job): bool => $job->environmentId === $environment->id);
});

test('saving other environment fields queues nothing', function (): void {
    $environment = fp_environment(Project::factory()->create(), 'production', 'deadbeef00');
    Queue::fake();

    $environment->update(['current_version' => '2.0.0', 'current_branch' => 'main', 'last_seen_at' => now()]);

    Queue::assertNotPushed(CheckFixPresenceJob::class);
});

test('a deployed commit changed in a rolled-back transaction queues nothing', function (): void {
    $environment = fp_environment(Project::factory()->create(), 'production', 'deadbeef00');
    Queue::fake();

    try {
        $environment->getConnection()->transaction(static function () use ($environment): void {
            $environment->update(['current_commit' => 'cafebabe11']);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
        // The rollback is the point of the test.
    }

    Queue::assertNotPushed(CheckFixPresenceJob::class);
});

test('a deployed commit changed in a committed transaction queues one check', function (): void {
    $environment = fp_environment(Project::factory()->create(), 'production', 'deadbeef00');
    Queue::fake();

    $environment->getConnection()->transaction(static function () use ($environment): void {
        $environment->update(['current_commit' => 'cafebabe11']);
    });

    Queue::assertPushedTimes(CheckFixPresenceJob::class, 1);
});

test('a fix with a commit queues a check of the change ref and a mention does not', function (): void {
    $project = Project::factory()->create();

    $fix = fp_fix($project, 'aaaaaaa1');
    fp_fix($project, 'bbbbbbb2', ChangeRefRelation::Mentions);

    Queue::assertPushedTimes(CheckFixPresenceJob::class, 1);
    Queue::assertPushed(CheckFixPresenceJob::class, static fn (CheckFixPresenceJob $job): bool => $job->changeRefId === $fix->id && $job->environmentId === null);
});

test('a fix gaining a commit queues a check and saving other fields does not', function (): void {
    $changeRef = ChangeRef::factory()->create(['ticket_id' => Ticket::factory(), 'commit_sha' => null]);
    Queue::assertNotPushed(CheckFixPresenceJob::class);

    $changeRef->update(['url' => 'https://example.com/commit/1']);
    Queue::assertNotPushed(CheckFixPresenceJob::class);

    $changeRef->update(['commit_sha' => 'aaaaaaa1']);
    Queue::assertPushedTimes(CheckFixPresenceJob::class, 1);
});

test('the job checks the environment it names', function (): void {
    $project = Project::factory()->create();
    fp_bind_vcs($project, fp_stub(true));
    fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');

    CheckFixPresenceJob::forEnvironment($environment->id)->handle(app(FixPresenceService::class));

    expect(FixPresence::query()->sole()->contained)->toBeTrue();
});

test('the job checks the change ref it names', function (): void {
    $project = Project::factory()->create();
    fp_bind_vcs($project, fp_stub(true));
    fp_environment($project, 'production', 'deadbeef00');
    $changeRef = fp_fix($project, 'aaaaaaa1');

    CheckFixPresenceJob::forChangeRef($changeRef->id)->handle(app(FixPresenceService::class));

    expect(FixPresence::query()->sole()->contained)->toBeTrue();
});

test('the job for a record deleted meanwhile does nothing', function (): void {
    $project = Project::factory()->create();
    $driver = fp_stub(true);
    fp_bind_vcs($project, $driver);
    $changeRef = fp_fix($project, 'aaaaaaa1');
    $environment = fp_environment($project, 'production', 'deadbeef00');
    $environmentId = $environment->id;
    $changeRefId = $changeRef->id;
    $environment->delete();
    $changeRef->delete();

    CheckFixPresenceJob::forEnvironment($environmentId)->handle(app(FixPresenceService::class));
    CheckFixPresenceJob::forChangeRef($changeRefId)->handle(app(FixPresenceService::class));
    CheckFixPresenceJob::forEnvironment(999_999)->handle(app(FixPresenceService::class));

    expect(FixPresence::query()->count())->toBe(0)
        ->and($driver->containsCommitCallCount())->toBe(0);
});
