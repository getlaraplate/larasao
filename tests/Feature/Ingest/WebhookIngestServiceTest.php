<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Enums\MatchType;
use Modules\SAO\Ingest\IngestReplayService;
use Modules\SAO\Ingest\PayloadMatcher;
use Modules\SAO\Ingest\PayloadNormalizer;
use Modules\SAO\Ingest\WebhookIngestService;
use Modules\SAO\Models\CorrelationRule;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Models\SourceProfile;

uses(RefreshDatabase::class);

function sao_glitchtip_profile(): SourceProfile
{
    return SourceProfile::factory()->create([
        'name' => 'glitchtip',
        'matchers' => [['path' => 'source', 'operator' => 'equals', 'value' => 'glitchtip']],
        'field_bindings' => [
            'message' => 'error.message',
            'class' => 'error.type',
            'file' => 'error.file',
            'project_key' => 'project.slug',
            'environment' => 'environment',
        ],
    ]);
}

/**
 * @return array<string, mixed>
 */
function sao_glitchtip_payload(string $projectKey): array
{
    return [
        'source' => 'glitchtip',
        'environment' => 'production',
        'project' => ['slug' => $projectKey],
        'error' => ['type' => 'RuntimeException', 'message' => 'boom id=1', 'file' => 'Modules/AI/app/X.php'],
    ];
}

test('the matcher and normalizer read dot-paths', function (): void {
    $profile = sao_glitchtip_profile();
    $payload = sao_glitchtip_payload('WIDGETS');

    expect(app(PayloadMatcher::class)->matches($profile, $payload))->toBeTrue()
        ->and(app(PayloadNormalizer::class)->normalize($profile, $payload))
        ->toMatchArray(['class' => 'RuntimeException', 'message' => 'boom id=1', 'project_key' => 'WIDGETS', 'environment' => 'production']);
});

test('a correlated error is recorded ingested and becomes a signal', function (): void {
    $project = Project::factory()->create(['key_prefix' => 'WIDGETS']);
    sao_glitchtip_profile();

    $event = app(WebhookIngestService::class)->ingest(null, 'delivery-1', sao_glitchtip_payload('WIDGETS'));

    expect($event->status)->toBe(IngestStatus::Ingested)
        ->and($event->outcome)->toBe('signal-recorded')
        ->and($event->winning_rule)->toBe('project_key')
        ->and($event->project_id)->toBe($project->id)
        ->and($event->signal_id)->not->toBeNull()
        ->and(Signal::query()->whereKey($event->signal_id)->exists())->toBeTrue();
});

test('a payload no profile matches is discarded with a reason', function (): void {
    sao_glitchtip_profile();

    $event = app(WebhookIngestService::class)->ingest(null, 'delivery-x', ['source' => 'unknown']);

    expect($event->status)->toBe(IngestStatus::Discarded)
        ->and($event->outcome)->toBe('no-matching-profile')
        ->and($event->signal_id)->toBeNull();
});

test('an error for an unknown project is recorded uncorrelated, no signal', function (): void {
    sao_glitchtip_profile();

    $event = app(WebhookIngestService::class)->ingest(null, 'delivery-2', sao_glitchtip_payload('NOPROJECT'));

    expect($event->status)->toBe(IngestStatus::Uncorrelated)
        ->and($event->outcome)->toBe('no-correlation-rule-matched')
        ->and($event->signal_id)->toBeNull()
        ->and(Signal::query()->count())->toBe(0);
});

test('a re-delivered id is recorded once', function (): void {
    Project::factory()->create(['key_prefix' => 'WIDGETS']);
    sao_glitchtip_profile();
    $service = app(WebhookIngestService::class);

    $first = $service->ingest(null, 'delivery-dup', sao_glitchtip_payload('WIDGETS'));
    $second = $service->ingest(null, 'delivery-dup', sao_glitchtip_payload('WIDGETS'));

    expect($second->id)->toBe($first->id)
        ->and(IngestEvent::query()->where('delivery_id', 'delivery-dup')->count())->toBe(1)
        ->and(Signal::query()->first()->occurrence_count)->toBe(1);
});

test('replay is a pure dry-run that writes nothing', function (): void {
    Project::factory()->create(['key_prefix' => 'WIDGETS']);
    $profile = sao_glitchtip_profile();
    $event = IngestEvent::factory()->create(['payload' => sao_glitchtip_payload('WIDGETS')]);

    $eventsBefore = IngestEvent::query()->count();
    $signalsBefore = Signal::query()->count();

    $result = app(IngestReplayService::class)->dryRun($event, $profile);

    expect($result['matches'])->toBeTrue()
        ->and($result['would_be_status'])->toBe(IngestStatus::Ingested->value)
        ->and($result['winning_rule'])->toBe('project_key')
        ->and(IngestEvent::query()->count())->toBe($eventsBefore)
        ->and(Signal::query()->count())->toBe($signalsBefore);
});

test('an operator rule routes a payload, records its id and writes its environment on the occurrence', function (): void {
    $project = Project::factory()->create();
    $profile = sao_glitchtip_profile();
    $profile->update(['field_bindings' => array_merge($profile->field_bindings, ['server_host' => 'host'])]);
    $rule = CorrelationRule::factory()->create([
        'project_id' => $project->getKey(),
        'target' => CorrelationTarget::ServerHost,
        'match' => MatchType::Wildcard,
        'pattern' => 'web-*',
        'environment' => 'staging',
    ]);
    $payload = sao_glitchtip_payload('UNKNOWN') + ['host' => 'web-01'];

    $event = app(WebhookIngestService::class)->ingest(null, 'delivery-rule', $payload);
    $dryRun = app(IngestReplayService::class)->dryRun($event, $profile);

    expect($event->status)->toBe(IngestStatus::Ingested)
        ->and($event->project_id)->toBe($project->id)
        ->and($event->winning_rule)->toBe("rule:{$rule->id}")
        ->and(SignalOccurrence::query()->where('signal_id', $event->signal_id)->value('environment'))->toBe('staging')
        ->and($dryRun['winning_rule'])->toBe("rule:{$rule->id}")
        ->and($dryRun['environment'])->toBe('staging');
});
