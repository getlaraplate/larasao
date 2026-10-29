<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\SAO\Drivers\Contracts\VcsCapability;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ChangeRefRelation;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\FixPresence;
use Modules\SAO\Models\ProjectBinding;
use Throwable;

/**
 * Records whether each environment contains each fix. The pairs are the project's
 * `Fixes` change refs that carry a `commit_sha` × the project's environments that
 * report a `current_commit`; each pair is asked of the project's `vcs` binding (the
 * first by id) through {@see VcsCapability::containsCommit()}, and the answer is
 * stored as a {@see FixPresence} for (change ref, environment, deployed commit).
 *
 * A pair whose row already holds a true or false answer is skipped: that answer
 * never changes for the same commit. A null answer (the VCS could not tell, or the
 * driver failed) is stored as null and asked again on the next check; it is never
 * written as false. A project without a usable `vcs` binding writes nothing.
 */
final readonly class FixPresenceService
{
    private const int CHUNK = 200;

    public function __construct(
        private DriverRegistry $registry,
        private ConnectionCredentialResolver $resolver,
    ) {}

    /**
     * Checks every fixing change ref of the environment's project against the
     * commit the environment runs. Returns the number of rows written.
     */
    public function checkEnvironment(Environment $environment): int
    {
        $deployedCommit = $this->deployedCommit($environment);

        if ($deployedCommit === null) {
            return 0;
        }

        $vcs = $this->vcsFor((int) $environment->project_id);

        if ($vcs === null) {
            return 0;
        }

        $settled = $this->settledChangeRefIds($environment, $deployedCommit);
        $written = 0;

        $this->fixesOfProject((int) $environment->project_id)
            ->lazyById(self::CHUNK)
            ->each(function (ChangeRef $changeRef) use ($vcs, $environment, $deployedCommit, $settled, &$written): void {
                if (isset($settled[$changeRef->getKey()])) {
                    return;
                }

                $this->record($vcs['driver'], $vcs['context'], $changeRef, $environment, $deployedCommit);
                $written++;
            });

        return $written;
    }

    /**
     * Checks one fixing change ref against every environment of its ticket's
     * project that reports a commit. Returns the number of rows written.
     */
    public function checkChangeRef(ChangeRef $changeRef): int
    {
        $sha = $changeRef->commit_sha;

        if ($changeRef->relation !== ChangeRefRelation::Fixes || $sha === null || $sha === '') {
            return 0;
        }

        $projectId = $changeRef->ticket?->project_id;

        if ($projectId === null) {
            return 0;
        }

        $vcs = $this->vcsFor((int) $projectId);

        if ($vcs === null) {
            return 0;
        }

        $environments = Environment::query()
            ->where('project_id', $projectId)
            ->whereNotNull('current_commit')
            ->where('current_commit', '!=', '')
            ->orderBy('id')
            ->get();

        $written = 0;

        foreach ($environments as $environment) {
            $deployedCommit = (string) $environment->current_commit;

            $isSettled = FixPresence::query()
                ->where('change_ref_id', $changeRef->getKey())
                ->where('environment_id', $environment->getKey())
                ->where('deployed_commit', $deployedCommit)
                ->whereNotNull('contained')
                ->exists();

            if ($isSettled) {
                continue;
            }

            $this->record($vcs['driver'], $vcs['context'], $changeRef, $environment, $deployedCommit);
            $written++;
        }

        return $written;
    }

    /**
     * Asks the VCS one pair and stores the answer. A driver that throws (beyond the
     * HTTP failures it already answers null for) costs only this pair: it is logged
     * and stored as null, to be asked again.
     */
    private function record(VcsCapability $driver, BindingContext $context, ChangeRef $changeRef, Environment $environment, string $deployedCommit): void
    {
        try {
            $contained = $driver->containsCommit($context, (string) $changeRef->commit_sha, $deployedCommit);
        } catch (Throwable $exception) {
            $contained = null;

            Log::warning('sao.fix_presence.check_failed', [
                'change_ref_id' => $changeRef->getKey(),
                'environment_id' => $environment->getKey(),
                'deployed_commit' => $deployedCommit,
                'exception' => $exception,
            ]);
        }

        // One atomic upsert on the unique triple: a concurrent run that inserted the
        // same pair first is overwritten rather than failing on the index, and a
        // row someone soft-deleted is revived with the fresh answer.
        FixPresence::query()->upsert(
            [[
                'change_ref_id' => $changeRef->getKey(),
                'environment_id' => $environment->getKey(),
                'deployed_commit' => $deployedCommit,
                'contained' => $contained,
                'checked_at' => now(),
                'deleted_at' => null,
            ]],
            ['change_ref_id', 'environment_id', 'deployed_commit'],
            ['contained', 'checked_at', 'deleted_at'],
        );
    }

    /**
     * The project's `vcs` binding (the first by id) resolved to a driver and a
     * binding context, or null when there is none or it cannot be used.
     *
     * @return array{driver: VcsCapability, context: BindingContext}|null
     */
    private function vcsFor(int $projectId): ?array
    {
        $binding = ProjectBinding::query()
            ->with('remoteConnection')
            ->where('project_id', $projectId)
            ->where('capability', Capability::Vcs)
            ->orderBy('id')
            ->first();

        if (! $binding instanceof ProjectBinding) {
            return null;
        }

        try {
            $driver = $this->registry->get($binding->remoteConnection->driver_key);

            if (! $driver instanceof VcsCapability) {
                return null;
            }

            return ['driver' => $driver, 'context' => $binding->bindingContext($this->resolver)];
        } catch (Throwable $exception) {
            Log::warning('sao.fix_presence.binding_unusable', [
                'project_id' => $projectId,
                'binding_id' => $binding->getKey(),
                'exception' => $exception,
            ]);

            return null;
        }
    }

    /**
     * @return Builder<ChangeRef>
     */
    private function fixesOfProject(int $projectId): Builder
    {
        return ChangeRef::query()
            ->fixes()
            ->whereNotNull('commit_sha')
            ->where('commit_sha', '!=', '')
            ->whereHas('ticket', static fn (Builder $ticket): Builder => $ticket->where('project_id', $projectId));
    }

    /**
     * The change refs whose presence in this environment at this commit is already
     * answered true or false, keyed by id.
     *
     * @return array<int, true>
     */
    private function settledChangeRefIds(Environment $environment, string $deployedCommit): array
    {
        return FixPresence::query()
            ->where('environment_id', $environment->getKey())
            ->where('deployed_commit', $deployedCommit)
            ->whereNotNull('contained')
            ->pluck('change_ref_id')
            ->mapWithKeys(static fn (int $id): array => [$id => true])
            ->all();
    }

    private function deployedCommit(Environment $environment): ?string
    {
        $commit = $environment->current_commit;

        return $commit === null || $commit === '' ? null : $commit;
    }
}
