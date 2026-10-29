<?php

declare(strict_types=1);

namespace Modules\SAO\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Modules\SAO\Drivers\Contracts\VersionProbeCapability;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Drivers\Support\ProbedVersion;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Exceptions\ProbeException;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Services\DeployCensusService;
use Throwable;

/**
 * Asks each environment which version it is running and records the answer in
 * the deploy census. Only projects with a `probe` binding are asked, and only
 * their environments that carry an operator-set `base_url`. A binding whose
 * project was deleted is ignored.
 *
 * One environment never aborts the run: a refused or failed probe is reported
 * and leaves that environment exactly as it was (it simply goes stale through
 * the census TTL), and the exit code is non-zero if any environment failed.
 */
final class ProbeCensusCommand extends Command
{
    /**
     * The length of the `current_version`, `current_commit` and `current_branch`
     * string columns: a probed value that does not fit is a failure, not a
     * database error.
     */
    private const int COLUMN_LENGTH = 255;

    /**
     * @var string
     */
    protected $signature = 'sao:census:probe {project? : The project key prefix to scope to; omit to probe them all}';

    /**
     * @var string
     */
    protected $description = 'Probe project environments for the version they run and record it in the deploy census <fg=bright-red>(🎫 Modules\SAO)</fg=bright-red>';

    public function handle(DriverRegistry $registry, ConnectionCredentialResolver $resolver, DeployCensusService $census): int
    {
        $prefix = $this->argument('project');
        $scoped = is_string($prefix) && $prefix !== '';

        if ($scoped && ! Project::query()->where('key_prefix', $prefix)->exists()) {
            $this->error("Project [{$prefix}] not found.");

            return self::FAILURE;
        }

        $bindings = ProjectBinding::query()
            ->with(['remoteConnection', 'project'])
            ->ofLiveProject()
            ->where('capability', Capability::Probe)
            ->when($scoped, static fn (Builder $query): Builder => $query
                ->whereHas('project', static fn (Builder $project): Builder => $project->where('key_prefix', $prefix)))
            ->orderBy('project_id')
            ->get();

        $failed = false;

        foreach ($bindings as $binding) {
            $project = $binding->project;
            $environments = $project->environments()
                ->whereNotNull('base_url')
                ->where('base_url', '!=', '')
                ->orderBy('name')
                ->get();

            foreach ($environments as $environment) {
                try {
                    $probed = $this->probe($registry, $resolver, $binding, $environment);
                    $this->guardColumnLengths($probed);
                    $census->recordProbe($environment, $probed->version, $probed->commit, $probed->branch);

                    $this->line("{$project->key_prefix} {$environment->name}: {$probed->version}");
                } catch (ProbeException $exception) {
                    $failed = true;
                    $this->error("{$project->key_prefix} {$environment->name}: failed — {$exception->getMessage()}");
                } catch (Throwable $exception) {
                    $failed = true;
                    $this->error("{$project->key_prefix} {$environment->name}: failed — " . $exception::class);

                    Log::warning('sao.census.probe.failed', [
                        'project_id' => $project->getKey(),
                        'environment_id' => $environment->getKey(),
                        'exception' => $exception,
                    ]);
                }
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @throws ProbeException
     */
    private function probe(DriverRegistry $registry, ConnectionCredentialResolver $resolver, ProjectBinding $binding, Environment $environment): ProbedVersion
    {
        $driver = $registry->get($binding->remoteConnection->driver_key);

        if (! $driver instanceof VersionProbeCapability) {
            throw new ProbeException("The driver [{$driver->key()}] cannot probe versions.");
        }

        $probed = $driver->probe($binding->bindingContext($resolver, credentialOptional: true), $environment);

        if (! $probed instanceof ProbedVersion) {
            throw new ProbeException('The driver returned no version.');
        }

        return $probed;
    }

    /**
     * @throws ProbeException
     */
    private function guardColumnLengths(ProbedVersion $probed): void
    {
        foreach (['version' => $probed->version, 'commit' => $probed->commit, 'branch' => $probed->branch] as $field => $value) {
            if ($value !== null && mb_strlen($value) > self::COLUMN_LENGTH) {
                throw new ProbeException("The probed {$field} is longer than " . self::COLUMN_LENGTH . ' characters.');
            }
        }
    }
}
