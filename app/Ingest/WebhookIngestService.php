<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;

/**
 * The generic ingest path: it records every delivery as an {@see IngestEvent}
 * with an explicit outcome, so silence is auditable without app logs. It runs
 * inside the {@see PipelineContext} (any error it logs is marked and never
 * re-ingested), dedupes by delivery id, selects a source profile, normalizes to
 * canonical fields, correlates to a project, and — for a correlated error —
 * hands the fields to {@see SignalIngestService}.
 */
final readonly class WebhookIngestService
{
    public function __construct(
        private ProfileSelector $profileSelector,
        private PayloadNormalizer $normalizer,
        private CorrelationRuleset $ruleset,
        private SignalIngestService $signalIngest,
        private PipelineContext $pipeline,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function ingest(?Connection $connection, string $deliveryId, array $payload): IngestEvent
    {
        return $this->pipeline->run(fn (): IngestEvent => $this->handle($connection, $deliveryId, $payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function handle(?Connection $connection, string $deliveryId, array $payload): IngestEvent
    {
        $connectionId = $connection?->getKey();

        $existing = IngestEvent::query()
            ->where('connection_id', $connectionId)
            ->where('delivery_id', $deliveryId)
            ->first();

        // Idempotency: a re-delivered id is recorded once, never re-ingested.
        if ($existing instanceof IngestEvent) {
            return $existing;
        }

        $event = new IngestEvent([
            'connection_id' => $connectionId,
            'delivery_id' => $deliveryId,
            'payload' => $payload,
            'status' => IngestStatus::Received,
        ]);

        $profile = $this->profileSelector->select($payload);

        if ($profile === null) {
            return $this->finish($event, IngestStatus::Discarded, 'no-matching-profile');
        }

        $event->source_profile_id = $profile->getKey();
        $canonical = $this->normalizer->normalize($profile, $payload);

        $correlation = $this->ruleset->correlate($canonical, CorrelationScope::forProfile($connection, $profile));

        if ($correlation['project'] === null) {
            return $this->finish($event, IngestStatus::Uncorrelated, 'no-correlation-rule-matched');
        }

        $canonical['environment'] = $correlation['environment'];

        $signal = $this->signalIngest->ingest($correlation['project'], $canonical);

        $event->project_id = $correlation['project']->getKey();
        $event->winning_rule = $correlation['rule'];
        $event->signal_id = $signal->getKey();

        return $this->finish($event, IngestStatus::Ingested, 'signal-recorded');
    }

    private function finish(IngestEvent $event, IngestStatus $status, string $outcome): IngestEvent
    {
        $event->status = $status;
        $event->outcome = $outcome;
        $event->save();

        return $event;
    }
}
