<?php

declare(strict_types=1);

use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\SourceProfile;

uses(RefreshDatabase::class);

test('a source profile stores its matchers and bindings as arrays', function (): void {
    $profile = SourceProfile::factory()->create()->fresh();

    expect($profile->matchers)->toBeArray()
        ->and($profile->field_bindings)->toBeArray()
        ->and($profile->is_active)->toBeTrue();
});

test('an ingest event records a status and casts its payload', function (): void {
    $event = IngestEvent::factory()->create([
        'payload' => ['a' => 1],
        'status' => IngestStatus::Ingested,
    ])->fresh();

    expect($event->payload)->toBe(['a' => 1])
        ->and($event->status)->toBe(IngestStatus::Ingested);
});

test('an ingest event resolves its optional relations', function (): void {
    $profile = SourceProfile::factory()->create();
    $event = IngestEvent::factory()->create(['source_profile_id' => $profile->id]);

    expect($event->sourceProfile->id)->toBe($profile->id)
        ->and($event->connection)->toBeNull()
        ->and($event->project)->toBeNull();
});

test('an ingest event defaults to the profile pipeline and is not reprocessed', function (): void {
    $event = IngestEvent::factory()->create()->fresh();

    expect($event->pipeline)->toBe(IngestPipeline::Profile)
        ->and($event->reprocessed_at)->toBeNull();
});

test('only the profile and logs pipelines can be reprocessed', function (): void {
    expect(IngestPipeline::Profile->isReprocessable())->toBeTrue()
        ->and(IngestPipeline::Logs->isReprocessable())->toBeTrue()
        ->and(IngestPipeline::Deploy->isReprocessable())->toBeFalse()
        ->and(IngestPipeline::Code->isReprocessable())->toBeFalse();
});

test('an ingest event casts reprocessed_at to a date', function (): void {
    $event = IngestEvent::factory()->create(['reprocessed_at' => '2026-10-29 10:00:00'])->fresh();

    expect($event->reprocessed_at)->toBeInstanceOf(CarbonInterface::class);
});
