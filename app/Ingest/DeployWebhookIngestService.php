<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Modules\SAO\Drivers\Contracts\DeployCapability;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Drivers\Support\DeployEvent;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Deployment;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;

/**
 * The inbound transport for a `deploy` connection's push deliveries: it turns one
 * raw HTTP webhook into deployments through the connection's own driver.
 *
 * It verifies the delivery signature with the driver (so a forged body never
 * reaches the store), unpacks it into normalized {@see DeployEvent}s, and — for
 * each project bound to the connection with the `deploy` capability — hands every
 * event to {@see DeploymentIngestService}, which records/advances the
 * {@see Deployment} and, on a terminal success, the environment census. Every
 * delivery is stored as an {@see IngestEvent}, deduped per (connection, delivery,
 * binding, index) so a re-delivery is recorded once and never re-ingested. The
 * whole run is wrapped in the {@see PipelineContext} so an error the ingest itself
 * logs cannot loop back in.
 */
final readonly class DeployWebhookIngestService
{
    public function __construct(
        private DriverRegistry $registry,
        private ConnectionCredentialResolver $resolver,
        private DeploymentIngestService $deploymentIngest,
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

        if (! $driver instanceof DeployCapability) {
            return WebhookIngestOutcome::unsupported('driver-has-no-deploy-capability');
        }

        $context = new BindingContext($connection->connectionContext($this->resolver->resolve($connection)));

        if (! $driver->verifySignature($context, $rawBody, $headers)) {
            return WebhookIngestOutcome::unauthorized();
        }

        $events = $driver->unpack($context, $rawBody);

        /** @var \Illuminate\Support\Collection<int, ProjectBinding> $bindings */
        $bindings = ProjectBinding::query()
            ->with('project')
            ->where('connection_id', $connection->getKey())
            ->where('capability', Capability::Deploy)
            ->get();

        if ($bindings->isEmpty()) {
            $this->record($connection, $deliveryId, $this->decode($rawBody), null, IngestStatus::Discarded, 'no-deploy-binding');

            return WebhookIngestOutcome::accepted('no-deploy-binding');
        }

        foreach ($bindings as $binding) {
            $project = $binding->project;

            if (! $project instanceof Project) {
                continue;
            }

            foreach ($events as $index => $event) {
                $eventDeliveryId = "{$deliveryId}#{$binding->getKey()}#{$index}";

                $existing = IngestEvent::query()
                    ->where('connection_id', $connection->getKey())
                    ->where('delivery_id', $eventDeliveryId)
                    ->exists();

                if ($existing) {
                    continue;
                }

                $deployment = $this->deploymentIngest->ingest($project, $event, $connection);

                $this->record($connection, $eventDeliveryId, $this->snapshot($event, $deployment), $project->getKey(), IngestStatus::Ingested, 'deployment-recorded');
            }
        }

        return WebhookIngestOutcome::accepted('deployments-recorded');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function record(Connection $connection, string $deliveryId, array $payload, ?int $projectId, IngestStatus $status, string $outcome): void
    {
        IngestEvent::query()->create([
            'connection_id' => $connection->getKey(),
            'pipeline' => IngestPipeline::Deploy,
            'delivery_id' => $deliveryId,
            'payload' => $payload,
            'status' => $status,
            'outcome' => $outcome,
            'project_id' => $projectId,
            'signal_id' => null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(DeployEvent $event, Deployment $deployment): array
    {
        return [
            'deployment_id' => $deployment->getKey(),
            'version' => $event->version,
            'status' => $event->status->value,
            'environment' => $event->environmentName,
            'external_id' => $event->externalId,
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
