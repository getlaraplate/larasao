<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Attribution\VcsScanService;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ChangeRefRelation;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Enums\TicketReleaseState;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketRelease;
use Modules\SAO\Tests\Support\Drivers\StubVcsDriver;

uses(RefreshDatabase::class);

/**
 * @param  list<array<string, mixed>>  $commits
 */
function bindStubVcs(array $commits, ?string $tag, Project $project): ProjectBinding
{
    app(DriverRegistry::class)->register(new StubVcsDriver($commits, $tag, 'stub-vcs'));

    $connection = Connection::factory()->create([
        'driver_key' => 'stub-vcs',
        'capabilities' => [Capability::Vcs, Capability::Releases],
        'base_url' => null,
        'credential' => ['token' => 'x'],
    ]);

    return ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Vcs,
        'remote_identifier' => 'acme/app',
    ]);
}

test('scanning links fixes and mentions and attributes fixing commits to releases', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();

    $binding = bindStubVcs([
        ['sha' => 'sha-fix', 'message' => "Fixes {$ticket->key}: guard null"],
        ['sha' => 'sha-mention', 'message' => "refactor near {$ticket->key}"],
        ['sha' => 'sha-unknown', 'message' => 'fixes ZZZ-999'],
    ], 'v1.4.0', $project);

    $report = app(VcsScanService::class)->scan($binding, 'main');

    expect($report->processed)->toBeTrue()
        ->and($report->commitsScanned)->toBe(3)
        ->and($report->fixLinks)->toBe(1)
        ->and($report->mentionLinks)->toBe(1)
        ->and($report->releasesAttributed)->toBe(1)
        ->and($report->unknownKeys)->toBe(['ZZZ-999']);

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->count())->toBe(2)
        ->and(ChangeRef::query()->where('identifier', 'sha-fix')->sole()->relation)->toBe(ChangeRefRelation::Fixes)
        ->and(ChangeRef::query()->where('identifier', 'sha-mention')->sole()->relation)->toBe(ChangeRefRelation::Mentions);

    $release = Release::query()->withoutGlobalScopes()->where('project_id', $project->id)->sole();

    expect($release->version)->toBe('1.4.0')
        ->and($release->status)->toBe(ReleaseStatus::Shipped)
        ->and(TicketRelease::query()->withoutGlobalScopes()->where('ticket_id', $ticket->id)->sole()->state)
        ->toBe(TicketReleaseState::Shipped);
});

test('re-scanning the same branch is idempotent', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();

    $binding = bindStubVcs([
        ['sha' => 'sha-fix', 'message' => "Closes {$ticket->key}"],
    ], 'v2.0.0', $project);

    app(VcsScanService::class)->scan($binding, 'main');
    app(VcsScanService::class)->scan($binding, 'main');

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and(Release::query()->withoutGlobalScopes()->where('project_id', $project->id)->count())->toBe(1)
        ->and(TicketRelease::query()->withoutGlobalScopes()->where('ticket_id', $ticket->id)->count())->toBe(1);
});

test('a non-vcs binding is skipped', function (): void {
    $project = Project::factory()->create();

    app(DriverRegistry::class)->register(new StubVcsDriver([], null, 'stub-vcs'));

    $connection = Connection::factory()->create([
        'driver_key' => 'stub-vcs',
        'capabilities' => [Capability::Vcs, Capability::Releases],
        'base_url' => null,
        'credential' => ['token' => 'x'],
    ]);
    $binding = ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Releases,
        'remote_identifier' => 'acme/app',
    ]);

    expect(app(VcsScanService::class)->scan($binding, 'main')->processed)->toBeFalse();
});

test('a scanned commit stores its own sha as the commit sha', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();

    $binding = bindStubVcs([
        ['sha' => 'ABC123', 'message' => "Fixes {$ticket->key}"],
    ], null, $project);

    app(VcsScanService::class)->scan($binding, 'main');

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)->toBe('abc123');
});
