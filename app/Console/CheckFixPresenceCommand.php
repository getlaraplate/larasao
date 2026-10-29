<?php

declare(strict_types=1);

namespace Modules\SAO\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;
use Modules\SAO\Services\FixPresenceService;

/**
 * Backfills fix presences: checks every environment of a project (or of every
 * project) against the project's fixes, synchronously, through
 * {@see FixPresenceService}. Known answers are not asked again and null answers
 * are retried, so rerunning it is safe.
 */
final class CheckFixPresenceCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sao:fix:check {project? : The project key prefix to scope to; omit to check them all}';

    /**
     * @var string
     */
    protected $description = 'Record whether each environment contains each fix <fg=bright-red>(🎫 Modules\SAO)</fg=bright-red>';

    public function handle(FixPresenceService $service): int
    {
        $prefix = $this->argument('project');
        $scoped = is_string($prefix) && $prefix !== '';

        $project = $scoped ? Project::query()->where('key_prefix', $prefix)->first() : null;

        if ($scoped && ! $project instanceof Project) {
            $this->error("Project [{$prefix}] not found.");

            return self::FAILURE;
        }

        $written = 0;

        Environment::query()
            ->when($project instanceof Project, static fn (Builder $query): Builder => $query->where('project_id', $project?->getKey()))
            ->whereNotNull('current_commit')
            ->where('current_commit', '!=', '')
            ->orderBy('id')
            ->each(static function (Environment $environment) use ($service, &$written): void {
                $written += $service->checkEnvironment($environment);
            });

        $this->info("{$written} fix presence row(s) written.");

        return self::SUCCESS;
    }
}
