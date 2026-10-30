<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Carbon\CarbonInterface;
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
     * Run one stored canonical item through the logs pipeline and save the row:
     * discarded when the connection has no `logs` binding, else correlated and
     * either recorded as uncorrelated or ingested into the project that won,
     * with the resolved environment written into the stored payload. The live
     * path calls it on a new row; reprocessing calls it on a stored one, passing
     * the delivery's original time so the occurrence keeps it. The signature is
     * checked only at receipt, never here.
     *
     * @param  CarbonInterface|null  $occurredAt  The original delivery time on a replay; null on a live delivery.
     */
    public function process(IngestEvent $event, Connection $connection, ?CarbonInterface $occurredAt = null): IngestEvent
    {
        $item = $event->payload;

        $hasLogsBinding = ProjectBinding::query()
            ->where('connection_id', $connection->getKey())
            ->where('capability', Capability::Logs)
            ->ofLiveProject()
            ->exists();

        if (! $hasLogsBinding) {
            return $this->finish($event, IngestStatus::Discarded, 'no-logs-binding');
        }

        $correlation = $this->ruleset->correlate($item, CorrelationScope::forLogs($connection));
        $project = $correlation['project'];

        if (! $project instanceof Project) {
            return $this->finish($event, IngestStatus::Uncorrelated, 'no-correlation-rule-matched');
        }

        $item['environment'] = $correlation['environment'];

        $signal = $this->signalIngest->ingest($project, $item, $occurredAt);
        $event->payload = $item;

        // getKey() is declared mixed on every Eloquent model; both of these
        // are saved records with an auto-incrementing key.
        return $this->finish($event, IngestStatus::Ingested, 'signal-recorded', (int) $project->getKey(), (int) $signal->getKey(), $correlation['rule']);
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
     * then recorded on a new row through {@see self::process()}.
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

        return $this->process(new IngestEvent([
            'connection_id' => $connection->getKey(),
            'pipeline' => IngestPipeline::Logs,
            'delivery_id' => $eventDeliveryId,
            'payload' => $event,
            'status' => IngestStatus::Received,
        ]), $connection);
    }

    private function finish(IngestEvent $event, IngestStatus $status, string $outcome, ?int $projectId = null, ?int $signalId = null, ?string $winningRule = null): IngestEvent
    {
        $event->status = $status;
        $event->outcome = $outcome;
        $event->project_id = $projectId;
        $event->winning_rule = $winningRule;
        $event->signal_id = $signalId;
        $event->save();

        return $event;
    }
}
