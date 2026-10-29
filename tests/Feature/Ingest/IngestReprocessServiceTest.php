<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Support\CrudApiExposure;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Enums\MatchType;
use Modules\SAO\Ingest\IngestReprocessService;
use Modules\SAO\Ingest\PipelineContext;
use Modules\SAO\Ingest\WebhookIngestService;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\CorrelationRule;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Models\SourceProfile;

uses(RefreshDatabase::class);

function reprocess_profile(): SourceProfile
{
    return SourceProfile::factory()->create([
        'name' => 'glitchtip',
        'matchers' => [['path' => 'source', 'operator' => 'equals', 'value' => 'glitchtip']],
        'field_bindings' => [
            'message' => 'error.message',
            'class' => 'error.type',
            'project_key' => 'project.slug',
            'server_host' => 'host',
        ],
    ]);
}

/**
 * @return array<string, mixed>
 */
function reprocess_payload(string $projectKey = 'UNKNOWN'): array
{
    return [
        'source' => 'glitchtip',
        'host' => 'web-01',
        'project' => ['slug' => $projectKey],
        'error' => ['type' => 'RuntimeException', 'message' => 'boom id=1'],
    ];
}

function reprocess_host_rule(Project $project): CorrelationRule
{
    return CorrelationRule::factory()->create([
        'project_id' => $project->getKey(),
        'target' => CorrelationTarget::ServerHost,
        'match' => MatchType::Exact,
        'pattern' => 'web-01',
    ]);
}

/**
 * An uncorrelated profile event recorded by the live path two days ago.
 */
function reprocess_uncorrelated_event(): IngestEvent
{
    reprocess_profile();
    test()->travelTo(now()->subDays(2)->startOfSecond());
    $event = app(WebhookIngestService::class)->ingest(null, 'delivery-1', reprocess_payload());
    test()->travelBack();

    return $event->fresh();
}

function reprocess_graylog_connection(): Connection
{
    return Connection::factory()->create([
        'driver_key' => 'graylog',
        'capabilities' => [Capability::Logs],
        'credential' => ['secret' => 'shared'],
        'base_url' => null,
    ]);
}

test('an uncorrelated profile event fixed by a new rule is ingested on the same row at its original time', function (): void {
    $event = reprocess_uncorrelated_event();
    $project = Project::factory()->create();
    $rule = reprocess_host_rule($project);

    $outcome = app(IngestReprocessService::class)->reprocess($event);

    $row = $event->fresh();

    expect($event->status)->toBe(IngestStatus::Uncorrelated)
        ->and($outcome->eventId)->toBe($event->id)
        ->and($outcome->before)->toBe(IngestStatus::Uncorrelated)
        ->and($outcome->after)->toBe(IngestStatus::Ingested)
        ->and($outcome->winningRule)->toBe("rule:{$rule->id}")
        ->and($outcome->applied)->toBeTrue()
        ->and(IngestEvent::query()->count())->toBe(1)
        ->and($row->status)->toBe(IngestStatus::Ingested)
        ->and($row->outcome)->toBe('signal-recorded')
        ->and($row->project_id)->toBe($project->id)
        ->and($row->winning_rule)->toBe("rule:{$rule->id}")
        ->and($row->reprocessed_at)->not->toBeNull()
        ->and(SignalOccurrence::query()->where('signal_id', $row->signal_id)->sole()->occurred_at->equalTo($row->created_at))->toBeTrue();
});

test('a logs event discarded for no binding is ingested once a binding exists, without re-verifying the signature', function (): void {
    CrudApiExposure::enable();
    $connection = reprocess_graylog_connection();

    $this->postJson('/api/v1/webhooks/' . $connection->getKey(), [
        'event_definition_title' => 'High error rate',
        'event' => ['source' => 'web-01'],
        'backlog' => [['message' => 'RuntimeException: one', 'source' => 'web-01', 'fields' => []]],
    ], ['X-Graylog-Token' => 'shared', 'X-Delivery-Id' => 'd1'])->assertStatus(202);

    $event = IngestEvent::query()->sole();
    $project = Project::factory()->create();
    ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Logs,
        'remote_identifier' => 'acme',
    ]);
    $connection->update(['credential' => ['secret' => 'rotated']]);

    $outcome = app(IngestReprocessService::class)->reprocess($event);

    $row = $event->fresh();

    expect($event->status)->toBe(IngestStatus::Discarded)
        ->and($outcome->after)->toBe(IngestStatus::Ingested)
        ->and($outcome->winningRule)->toBe('binding')
        ->and($row->status)->toBe(IngestStatus::Ingested)
        ->and($row->delivery_id)->toBe('d1#0')
        ->and($row->project_id)->toBe($project->id)
        ->and($row->signal_id)->not->toBeNull()
        ->and($row->reprocessed_at)->not->toBeNull()
        ->and(Signal::query()->sole()->project_id)->toBe($project->id);
});

test('a failed event is eligible', function (): void {
    $project = Project::factory()->create(['key_prefix' => 'WIDGETS']);
    reprocess_profile();
    $event = IngestEvent::factory()->create([
        'pipeline' => IngestPipeline::Profile,
        'payload' => reprocess_payload('WIDGETS'),
        'status' => IngestStatus::Failed,
        'outcome' => RuntimeException::class,
    ]);

    $outcome = app(IngestReprocessService::class)->reprocess($event);

    expect($outcome->applied)->toBeTrue()
        ->and($outcome->before)->toBe(IngestStatus::Failed)
        ->and($outcome->after)->toBe(IngestStatus::Ingested)
        ->and($event->fresh()->project_id)->toBe($project->id)
        ->and($event->fresh()->winning_rule)->toBe('project_key');
});

test('an ineligible event is not reprocessed and nothing changes', function (IngestPipeline $pipeline, IngestStatus $status): void {
    Project::factory()->create(['key_prefix' => 'WIDGETS']);
    reprocess_profile();
    $event = IngestEvent::factory()->create([
        'pipeline' => $pipeline,
        'payload' => reprocess_payload('WIDGETS'),
        'status' => $status,
        'outcome' => 'kept',
    ]);

    $outcome = app(IngestReprocessService::class)->reprocess($event);

    $row = $event->fresh();

    expect($outcome->applied)->toBeFalse()
        ->and($outcome->before)->toBe($status)
        ->and($outcome->after)->toBe($status)
        ->and($row->status)->toBe($status)
        ->and($row->outcome)->toBe('kept')
        ->and($row->reprocessed_at)->toBeNull()
        ->and(Signal::query()->count())->toBe(0);
})->with([
    'ingested profile' => [IngestPipeline::Profile, IngestStatus::Ingested],
    'discarded deploy' => [IngestPipeline::Deploy, IngestStatus::Discarded],
    'uncorrelated code' => [IngestPipeline::Code, IngestStatus::Uncorrelated],
]);

test('a dry run reports the would-be status and writes nothing', function (): void {
    $event = reprocess_uncorrelated_event();
    $rule = reprocess_host_rule(Project::factory()->create());
    $context = app(PipelineContext::class);

    $outcome = app(IngestReprocessService::class)->reprocess($event, dryRun: true);

    $row = $event->fresh();

    expect($outcome->after)->toBe(IngestStatus::Ingested)
        ->and($outcome->winningRule)->toBe("rule:{$rule->id}")
        ->and($outcome->applied)->toBeFalse()
        ->and(Signal::query()->count())->toBe(0)
        ->and(SignalOccurrence::query()->count())->toBe(0)
        ->and($row->status)->toBe(IngestStatus::Uncorrelated)
        ->and($row->project_id)->toBeNull()
        ->and($row->winning_rule)->toBeNull()
        ->and($row->reprocessed_at)->toBeNull()
        ->and($context->isActive())->toBeFalse();
});

test('reprocessing the same row twice ingests once', function (): void {
    $event = reprocess_uncorrelated_event();
    reprocess_host_rule(Project::factory()->create());
    $service = app(IngestReprocessService::class);

    $first = $service->reprocess($event);
    $second = $service->reprocess($event);

    expect($first->applied)->toBeTrue()
        ->and($second->applied)->toBeFalse()
        ->and($second->before)->toBe(IngestStatus::Ingested)
        ->and(Signal::query()->sole()->occurrence_count)->toBe(1)
        ->and(SignalOccurrence::query()->count())->toBe(1);
});

test('an exception while reprocessing marks the row failed and rolls back the partial work', function (): void {
    $event = reprocess_uncorrelated_event();
    reprocess_host_rule(Project::factory()->create());
    SignalOccurrence::creating(static function (): never {
        throw new LogicException('occurrence store down');
    });

    $outcome = app(IngestReprocessService::class)->reprocess($event);

    $row = $event->fresh();

    expect($outcome->after)->toBe(IngestStatus::Failed)
        ->and($outcome->applied)->toBeTrue()
        ->and($row->status)->toBe(IngestStatus::Failed)
        ->and($row->outcome)->toBe(LogicException::class)
        ->and($row->project_id)->toBeNull()
        ->and($row->signal_id)->toBeNull()
        ->and(Signal::query()->count())->toBe(0)
        ->and(app(PipelineContext::class)->isActive())->toBeFalse();
});

test('reprocessMany reprocesses every row of the query', function (): void {
    $project = Project::factory()->create(['key_prefix' => 'WIDGETS']);
    reprocess_profile();
    $events = IngestEvent::factory()->count(2)->create([
        'pipeline' => IngestPipeline::Profile,
        'payload' => reprocess_payload('WIDGETS'),
        'status' => IngestStatus::Uncorrelated,
    ]);

    $outcomes = app(IngestReprocessService::class)->reprocessMany(IngestEvent::query());

    expect($outcomes)->toHaveCount(2)
        ->and($outcomes->pluck('eventId')->all())->toBe($events->pluck('id')->all())
        ->and($outcomes->every(fn ($outcome): bool => $outcome->applied && $outcome->after === IngestStatus::Ingested))->toBeTrue()
        ->and(Signal::query()->sole()->project_id)->toBe($project->id);
});
