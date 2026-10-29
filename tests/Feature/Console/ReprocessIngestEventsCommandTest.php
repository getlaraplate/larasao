<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SourceProfile;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Project::factory()->create(['key_prefix' => 'WIDGETS']);
    SourceProfile::factory()->create([
        'name' => 'glitchtip',
        'matchers' => [['path' => 'source', 'operator' => 'equals', 'value' => 'glitchtip']],
        'field_bindings' => ['message' => 'error.message', 'project_key' => 'project.slug'],
    ]);
});

/**
 * @param  array<string, mixed>  $attributes
 */
function reprocess_command_event(IngestStatus $status, array $attributes = []): IngestEvent
{
    return IngestEvent::factory()->create(array_merge([
        'pipeline' => IngestPipeline::Profile,
        'payload' => ['source' => 'glitchtip', 'project' => ['slug' => 'WIDGETS'], 'error' => ['message' => 'boom ' . uniqid()]],
        'status' => $status,
    ], $attributes));
}

test('it reprocesses every eligible event by default and prints one line each and a total', function (): void {
    $uncorrelated = reprocess_command_event(IngestStatus::Uncorrelated);
    $failed = reprocess_command_event(IngestStatus::Failed);
    $ingested = reprocess_command_event(IngestStatus::Ingested);

    $this->artisan('sao:ingest:reprocess')
        ->expectsOutputToContain("#{$uncorrelated->id} uncorrelated -> ingested")
        ->expectsOutputToContain("#{$failed->id} failed -> ingested")
        ->doesntExpectOutputToContain("#{$ingested->id} ")
        ->expectsOutputToContain('2 event(s) checked, 2 reprocessed.')
        ->assertSuccessful();

    expect($uncorrelated->fresh()->status)->toBe(IngestStatus::Ingested)
        ->and($failed->fresh()->status)->toBe(IngestStatus::Ingested);
});

test('it filters by status', function (): void {
    $uncorrelated = reprocess_command_event(IngestStatus::Uncorrelated);
    $failed = reprocess_command_event(IngestStatus::Failed);

    $this->artisan('sao:ingest:reprocess', ['--status' => 'failed'])
        ->expectsOutputToContain('1 event(s) checked, 1 reprocessed.')
        ->assertSuccessful();

    expect($failed->fresh()->status)->toBe(IngestStatus::Ingested)
        ->and($uncorrelated->fresh()->status)->toBe(IngestStatus::Uncorrelated);
});

test('it filters by connection', function (): void {
    $connection = Connection::factory()->create(['driver_key' => 'graylog', 'capabilities' => [Capability::Logs], 'base_url' => null]);
    $onConnection = reprocess_command_event(IngestStatus::Uncorrelated, ['connection_id' => $connection->getKey()]);
    $elsewhere = reprocess_command_event(IngestStatus::Uncorrelated);

    $this->artisan('sao:ingest:reprocess', ['--connection' => (string) $connection->getKey()])
        ->expectsOutputToContain('1 event(s) checked, 1 reprocessed.')
        ->assertSuccessful();

    expect($onConnection->fresh()->status)->toBe(IngestStatus::Ingested)
        ->and($elsewhere->fresh()->status)->toBe(IngestStatus::Uncorrelated);
});

test('it filters by --since', function (): void {
    $old = reprocess_command_event(IngestStatus::Uncorrelated, ['created_at' => now()->subDays(10)]);
    $recent = reprocess_command_event(IngestStatus::Uncorrelated, ['created_at' => now()->subDay()]);

    $this->artisan('sao:ingest:reprocess', ['--since' => now()->subDays(3)->toDateString()])
        ->expectsOutputToContain('1 event(s) checked, 1 reprocessed.')
        ->assertSuccessful();

    expect($recent->fresh()->status)->toBe(IngestStatus::Ingested)
        ->and($old->fresh()->status)->toBe(IngestStatus::Uncorrelated);
});

test('it reprocesses a single event by id and reports an ineligible one as skipped', function (): void {
    $target = reprocess_command_event(IngestStatus::Uncorrelated);
    $other = reprocess_command_event(IngestStatus::Uncorrelated);
    $ingested = reprocess_command_event(IngestStatus::Ingested);

    $this->artisan('sao:ingest:reprocess', ['event' => (string) $target->id])
        ->expectsOutputToContain("#{$target->id} uncorrelated -> ingested")
        ->expectsOutputToContain('1 event(s) checked, 1 reprocessed.')
        ->assertSuccessful();

    $this->artisan('sao:ingest:reprocess', ['event' => (string) $ingested->id])
        ->expectsOutputToContain("#{$ingested->id} ingested -> ingested - [skipped]")
        ->assertSuccessful();

    expect($target->fresh()->status)->toBe(IngestStatus::Ingested)
        ->and($other->fresh()->status)->toBe(IngestStatus::Uncorrelated);
});

test('a dry run reports and writes nothing', function (): void {
    $event = reprocess_command_event(IngestStatus::Uncorrelated);

    $this->artisan('sao:ingest:reprocess', ['--dry-run' => true])
        ->expectsOutputToContain("#{$event->id} uncorrelated -> ingested")
        ->expectsOutputToContain('Dry run: 1 event(s) checked, nothing was written.')
        ->assertSuccessful();

    expect($event->fresh()->status)->toBe(IngestStatus::Uncorrelated)
        ->and(Signal::query()->count())->toBe(0);
});

test('it fails on invalid input', function (array $arguments): void {
    reprocess_command_event(IngestStatus::Uncorrelated);

    $this->artisan('sao:ingest:reprocess', $arguments)->assertFailed();

    expect(Signal::query()->count())->toBe(0);
})->with([
    'unknown event' => [['event' => '999999']],
    'unknown status' => [['--status' => 'nope']],
    'invalid since' => [['--since' => 'not-a-date']],
    'invalid connection' => [['--connection' => 'abc']],
]);

test('an invalid --since says why', function (): void {
    $this->artisan('sao:ingest:reprocess', ['--since' => 'not-a-date'])
        ->expectsOutputToContain('The --since value [not-a-date] is not a valid date.')
        ->assertFailed();
});
