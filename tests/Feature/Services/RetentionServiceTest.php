<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Tests\Stubs\Database\Db2PretendConnection;
use Modules\SAO\Models\Deployment;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Services\RetentionService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->retention = app(RetentionService::class);
});

test('it prunes signal occurrences older than the window and dry-run deletes nothing', function (): void {
    $signal = Signal::factory()->create();
    SignalOccurrence::factory()->count(3)->create(['signal_id' => $signal->id, 'occurred_at' => now()->subDays(120)]);
    SignalOccurrence::factory()->count(2)->create(['signal_id' => $signal->id, 'occurred_at' => now()->subDays(10)]);

    expect($this->retention->pruneSignalOccurrences(90, dryRun: true))->toBe(3)
        ->and(SignalOccurrence::query()->count())->toBe(5);

    expect($this->retention->pruneSignalOccurrences(90, dryRun: false))->toBe(3)
        ->and(SignalOccurrence::query()->count())->toBe(2);
});

test('it prunes ingest events older than the window', function (): void {
    $old = IngestEvent::factory()->create();
    IngestEvent::query()->whereKey($old->id)->update(['created_at' => now()->subDays(60)]);
    IngestEvent::factory()->create();

    expect($this->retention->pruneIngestEvents(30, dryRun: false))->toBe(1)
        ->and(IngestEvent::query()->count())->toBe(1);
});

test('it prunes old deployments but keeps the latest per environment', function (): void {
    $project = Project::factory()->create();
    $env = Environment::factory()->for($project)->create(['name' => 'production']);

    Deployment::factory()->for($project)->create(['environment_id' => $env->id, 'started_at' => now()->subDays(300)]);
    Deployment::factory()->for($project)->create(['environment_id' => $env->id, 'started_at' => now()->subDays(250)]);
    $latest = Deployment::factory()->for($project)->create(['environment_id' => $env->id, 'started_at' => now()->subDays(200)]);

    expect($this->retention->pruneDeployments(180, dryRun: false))->toBe(2)
        ->and(Deployment::query()->whereKey($latest->id)->exists())->toBeTrue()
        ->and(Deployment::query()->count())->toBe(1);
});

test('it purges the heavy data of a long-closed project but keeps the project', function (): void {
    $active = Project::factory()->create(['is_active' => true]);
    $closed = Project::factory()->create();
    Project::query()->whereKey($closed->id)->update(['is_active' => false, 'updated_at' => now()->subDays(60)]);

    foreach ([$active, $closed] as $project) {
        Signal::factory()->for($project)->create();
        IngestEvent::factory()->create(['project_id' => $project->id]);
        Deployment::factory()->for($project)->create();
        Ticket::factory()->for($project)->create();
    }

    $this->retention->pruneClosedProjects(30, dryRun: false);

    expect(Signal::query()->where('project_id', $closed->id)->count())->toBe(0)
        ->and(Deployment::query()->where('project_id', $closed->id)->count())->toBe(0)
        ->and(Ticket::query()->where('project_id', $closed->id)->count())->toBe(0)
        ->and(IngestEvent::query()->where('project_id', $closed->id)->count())->toBe(0)
        // The active project and the closed project's own anagraphic row are kept.
        ->and(Signal::query()->where('project_id', $active->id)->count())->toBe(1)
        ->and(Project::query()->whereKey($closed->id)->exists())->toBeTrue();
});

test('a recently-closed project inside the grace period is left alone', function (): void {
    $closed = Project::factory()->create();
    Project::query()->whereKey($closed->id)->update(['is_active' => false, 'updated_at' => now()->subDays(5)]);
    Signal::factory()->for($closed)->create();

    expect($this->retention->pruneClosedProjects(30, dryRun: false))->toBe(0)
        ->and(Signal::query()->where('project_id', $closed->id)->count())->toBe(1);
});

test('it finds the latest deployment of each pair with the reserved id column quoted on Db2', function (): void {
    $sql = Db2PretendConnection::capture(function (): void {
        $this->retention->pruneDeployments(90, dryRun: true);
    });

    expect($sql[0])->toStartWith('select MAX("ID") as latest_id from sao_deployments')
        ->and($sql[0])->not->toContain('MAX(id)');
});
