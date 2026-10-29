<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Modules\SAO\Attribution\CodeReference;
use Modules\SAO\Attribution\CodeReferenceWriter;
use Modules\SAO\Drivers\Contracts\CodeEventCapability;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\ProjectBinding;

/**
 * The inbound transport for a `code` connection's push deliveries (a merged PR):
 * it turns one raw HTTP webhook into ticket change refs through the connection's
 * own driver.
 *
 * It verifies the delivery signature with the driver (so a forged body never
 * reaches the store), unpacks it into normalized {@see CodeReference}s, and — for
 * each project bound to the connection with the `code` capability — records them
 * through {@see CodeReferenceWriter} (which resolves the ticket keys and upserts
 * the change refs). Every delivery is stored as an {@see IngestEvent}, deduped per
 * (connection, delivery, binding, index). Release attribution is left to the pull
 * scan, which carries real commit shas; a just-merged PR rarely sits in a tag yet.
 */
final readonly class CodeWebhookIngestService
{
    public function __construct(
        private DriverRegistry $registry,
        private ConnectionCredentialResolver $resolver,
        private CodeReferenceWriter $writer,
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

        if (! $driver instanceof CodeEventCapability) {
            return WebhookIngestOutcome::unsupported('driver-has-no-code-capability');
        }

        $context = new BindingContext($connection->connectionContext($this->resolver->resolve($connection)));

        if (! $driver->verifySignature($context, $rawBody, $headers)) {
            return WebhookIngestOutcome::unauthorized();
        }

        $references = $driver->unpack($context, $rawBody);

        /** @var \Illuminate\Support\Collection<int, ProjectBinding> $bindings */
        $bindings = ProjectBinding::query()
            ->with('project')
            ->where('connection_id', $connection->getKey())
            ->where('capability', Capability::Code)
            ->get();

        if ($bindings->isEmpty()) {
            $this->record($connection, $deliveryId, $this->decode($rawBody), null, IngestStatus::Discarded, 'no-code-binding');

            return WebhookIngestOutcome::accepted('no-code-binding');
        }

        foreach ($bindings as $binding) {
            $projectId = $binding->project?->getKey();

            foreach ($references as $index => $reference) {
                $eventDeliveryId = "{$deliveryId}#{$binding->getKey()}#{$index}";

                $existing = IngestEvent::query()
                    ->where('connection_id', $connection->getKey())
                    ->where('delivery_id', $eventDeliveryId)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $outcome = $this->writer->write($reference);

                $this->record($connection, $eventDeliveryId, $this->snapshot($reference, $outcome->changeRefs, $outcome->unknownKeys), $projectId, IngestStatus::Ingested, 'change-refs-recorded');
            }
        }

        return WebhookIngestOutcome::accepted('code-recorded');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Connection $connection, string $deliveryId, array $payload, ?int $projectId, IngestStatus $status, string $outcome): void
    {
        IngestEvent::query()->create([
            'connection_id' => $connection->getKey(),
            'pipeline' => IngestPipeline::Code,
            'delivery_id' => $deliveryId,
            'payload' => $payload,
            'status' => $status,
            'outcome' => $outcome,
            'project_id' => $projectId,
            'signal_id' => null,
        ]);
    }

    /**
     * @param  list<\Modules\SAO\Models\ChangeRef>  $changeRefs
     * @param  list<string>  $unknownKeys
     * @return array<string, mixed>
     */
    private function snapshot(CodeReference $reference, array $changeRefs, array $unknownKeys): array
    {
        return [
            'type' => $reference->type->value,
            'identifier' => $reference->identifier,
            'change_ref_ids' => array_map(static fn ($changeRef): int => (int) $changeRef->getKey(), $changeRefs),
            'unknown_keys' => $unknownKeys,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);

        return is_array($decoded) ? $decoded : [];
    }
}
