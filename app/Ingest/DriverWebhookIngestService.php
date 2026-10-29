<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Modules\SAO\Drivers\Contracts\LogsCapability;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;

/**
 * The inbound transport for a `logs` connection's push deliveries: it turns one
 * raw HTTP webhook into signals through the connection's own driver.
 *
 * It verifies the delivery signature with the driver (so a forged body never
 * reaches the store), unpacks it into the driver's canonical events, and
 * correlates every event on its own with {@see CorrelationRuleset}: the winning
 * project receives it through {@see SignalIngestService}, which resolves the
 * group key (native or computed) and records the occurrence. An event the
 * ruleset cannot place, or one received while the connection has no `logs`
 * binding, is recorded as uncorrelated or discarded and produces no signal.
 * Every event is stored as an {@see IngestEvent} with the delivery id
 * `{delivery}#{index}`, deduped per (connection, delivery id) so a re-delivery
 * is recorded once and never re-correlated or re-ingested. The whole run is
 * wrapped in the {@see PipelineContext} so an error the ingest itself logs
 * cannot loop back in.
 */
final readonly class DriverWebhookIngestService
{
    public function __construct(
        private DriverRegistry $registry,
        private ConnectionCredentialResolver $resolver,
        private SignalIngestService $signalIngest,
        private CorrelationRuleset $ruleset,
        private PipelineContext $pipeline,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function ingest(Connection $connection, string $deliveryId, string $rawBody, array $headers): WebhookIngestOutcome
    {
        return $this->pipeline->run(fn (): WebhookIngestOutcome => $this->handle($connection, $deliveryId, $rawBody, $headers));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function handle(Connection $connection, string $deliveryId, string $rawBody, array $headers): WebhookIngestOutcome
    {
        $driver = $connection->driver($this->registry);

        if (! $driver instanceof LogsCapability) {
            return WebhookIngestOutcome::unsupported('driver-has-no-logs-capability');
        }

        $context = new BindingContext($connection->connectionContext($this->resolver->resolve($connection)));

        if (! $driver->verifySignature($context, $rawBody, $headers)) {
            return WebhookIngestOutcome::unauthorized();
        }

        $signalIds = [];
        $discarded = false;

        foreach ($driver->unpack($context, $rawBody)->items as $index => $event) {
            $recorded = $this->processItem($connection, "{$deliveryId}#{$index}", $event);

            if ($recorded->signal_id !== null) {
                $signalIds[] = (int) $recorded->signal_id;
            }

            $discarded = $discarded || $recorded->status === IngestStatus::Discarded;
        }

        if ($discarded && $signalIds === []) {
            return WebhookIngestOutcome::accepted('no-logs-binding');
        }

        return WebhookIngestOutcome::accepted('ingested', array_values(array_unique($signalIds)));
    }

    /**
     * One event of a delivery, on its own: it is deduped on its own delivery id,
     * discarded when the connection has no `logs` binding, else correlated and
     * either recorded as uncorrelated or ingested into the project that won.
     *
     * @param  array<string, mixed>  $event
     */
    private function processItem(Connection $connection, string $eventDeliveryId, array $event): IngestEvent
    {
        $existing = IngestEvent::query()
            ->where('connection_id', $connection->getKey())
            ->where('delivery_id', $eventDeliveryId)
            ->first();

        // Idempotency: a re-sent item is recorded once and never re-correlated,
        // so a rule added since cannot move it.
        if ($existing instanceof IngestEvent) {
            return $existing;
        }

        $hasLogsBinding = ProjectBinding::query()
            ->where('connection_id', $connection->getKey())
            ->where('capability', Capability::Logs)
            ->exists();

        if (! $hasLogsBinding) {
            return $this->record($connection, $eventDeliveryId, $event, null, null, IngestStatus::Discarded, 'no-logs-binding', null);
        }

        $correlation = $this->ruleset->correlate($event, CorrelationScope::forLogs($connection));
        $project = $correlation['project'];

        if (! $project instanceof Project) {
            return $this->record($connection, $eventDeliveryId, $event, null, null, IngestStatus::Uncorrelated, 'no-correlation-rule-matched', null);
        }

        $event['environment'] = $correlation['environment'];

        $signal = $this->signalIngest->ingest($project, $event);

        // getKey() is declared mixed on every Eloquent model; both of these
        // are saved records with an auto-incrementing key.
        return $this->record($connection, $eventDeliveryId, $event, (int) $project->getKey(), (int) $signal->getKey(), IngestStatus::Ingested, 'signal-recorded', $correlation['rule']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Connection $connection, string $deliveryId, array $payload, ?int $projectId, ?int $signalId, IngestStatus $status, string $outcome, ?string $winningRule): IngestEvent
    {
        return IngestEvent::query()->create([
            'connection_id' => $connection->getKey(),
            'pipeline' => IngestPipeline::Logs,
            'delivery_id' => $deliveryId,
            'payload' => $payload,
            'status' => $status,
            'outcome' => $outcome,
            'project_id' => $projectId,
            'winning_rule' => $winningRule,
            'signal_id' => $signalId,
        ]);
    }
}
