<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Modules\SAO\Drivers\Support\DeployEvent;
use Modules\SAO\Events\DeploymentRecorded;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Deployment;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;
use Modules\SAO\Services\DeployCensusService;

/**
 * The `deploy` counterpart to {@see SignalIngestService}: it turns one normalized
 * deploy event into a durable {@see Deployment}, deduped by (connection,
 * external_id) so a re-delivery advances the same record instead of duplicating
 * it. On a terminal `succeeded` deploy it advances the environment's version
 * through {@see DeployCensusService}, so the census becomes a projection of this
 * history rather than a hand-set snapshot. Every persist announces
 * {@see DeploymentRecorded} — the seam release-health and automation subscribe to.
 */
final readonly class DeploymentIngestService
{
    public function __construct(private DeployCensusService $census) {}

    public function ingest(Project $project, DeployEvent $event, ?Connection $connection = null): Deployment
    {
        $environment = $this->resolveEnvironment($project, $event->environmentName);
        $release = $this->resolveRelease($project, $event->version);

        $deployment = $this->locate($project, $connection, $event->externalId);

        $isNew = ! $deployment->exists;

        if ($isNew) {
            $deployment->project_id = $project->getKey();
            $deployment->connection_id = $connection?->getKey();
            $deployment->external_id = $event->externalId;
            $deployment->started_at = $event->startedAt ?? now();
        }

        $deployment->environment_id = $environment?->getKey();
        $deployment->release_id = $release?->getKey();
        $deployment->version = $event->version;
        $deployment->status = $event->status;
        $deployment->meta = $event->meta === [] ? null : $event->meta;

        if ($event->status->isTerminal()) {
            $deployment->finished_at = $event->finishedAt ?? $deployment->finished_at ?? now();
        }

        $deployment->save();

        if ($event->status->isSuccessful() && $environment instanceof Environment) {
            $this->census->observe(
                $environment,
                $event->version,
                is_string($event->meta['sha'] ?? null) ? $event->meta['sha'] : null,
                is_string($event->meta['ref'] ?? null) ? $event->meta['ref'] : null,
            );
        }

        event(new DeploymentRecorded($deployment, $isNew));

        return $deployment;
    }

    /**
     * The existing deployment for this idempotency key, or a fresh unsaved model.
     * Without both a connection and an external id there is no durable key, so
     * every such call records a new deployment.
     */
    private function locate(Project $project, ?Connection $connection, ?string $externalId): Deployment
    {
        if ($externalId === null) {
            return new Deployment;
        }

        $existing = Deployment::query()
            ->where('project_id', $project->getKey())
            ->where('connection_id', $connection?->getKey())
            ->where('external_id', $externalId)
            ->first();

        return $existing ?? new Deployment;
    }

    private function resolveEnvironment(Project $project, ?string $name): ?Environment
    {
        if ($name === null || $name === '') {
            return null;
        }

        return Environment::query()->firstOrCreate([
            'project_id' => $project->getKey(),
            'name' => $name,
        ]);
    }

    private function resolveRelease(Project $project, string $version): ?Release
    {
        return Release::query()
            ->where('project_id', $project->getKey())
            ->where('version', $version)
            ->first();
    }
}
