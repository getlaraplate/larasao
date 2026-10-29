<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\FixPresence;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Tests\Support\Drivers\StubVcsDriver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Keep the saved hooks from computing presences while the fixtures are written,
    // so every row is the command's.
    Queue::fake();

    app(DriverRegistry::class)->register(new StubVcsDriver(key: 'stub-vcs', containsCommit: true));
});

/**
 * A project with a `vcs` binding, one fixing change ref and one environment
 * reporting a commit.
 */
function fix_check_project(string $keyPrefix): Project
{
    $project = Project::factory()->create(['key_prefix' => $keyPrefix]);

    $connection = Connection::factory()->create([
        'driver_key' => 'stub-vcs',
        'capabilities' => [Capability::Vcs],
        'base_url' => null,
        'credential' => ['token' => 'x'],
    ]);

    ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Vcs,
        'remote_identifier' => "acme/{$keyPrefix}",
    ]);

    ChangeRef::factory()->create([
        'ticket_id' => Ticket::factory()->forProject($project),
        'commit_sha' => 'aaaaaaa1',
    ]);

    Environment::factory()->create([
        'project_id' => $project->getKey(),
        'name' => 'production',
        'current_commit' => 'deadbeef00',
    ]);

    return $project;
}

test('it backfills the presences of one project', function (): void {
    $shop = fix_check_project('SHP');
    fix_check_project('BLG');

    $this->artisan('sao:fix:check', ['project' => 'SHP'])
        ->expectsOutputToContain('1 fix presence row(s) written.')
        ->assertSuccessful();

    $presence = FixPresence::query()->sole();

    expect($presence->environment->project_id)->toBe($shop->id)
        ->and($presence->contained)->toBeTrue();
});

test('without a project it backfills every project', function (): void {
    fix_check_project('SHP');
    fix_check_project('BLG');

    $this->artisan('sao:fix:check')
        ->expectsOutputToContain('2 fix presence row(s) written.')
        ->assertSuccessful();

    expect(FixPresence::query()->count())->toBe(2);
});

test('an unknown project is reported and fails', function (): void {
    $this->artisan('sao:fix:check', ['project' => 'NOPE'])
        ->expectsOutputToContain('Project [NOPE] not found.')
        ->assertFailed();

    expect(FixPresence::query()->count())->toBe(0);
});
