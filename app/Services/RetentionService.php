<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\SAO\Models\Deployment;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Models\Ticket;

/**
 * Hard-deletes aged, high-volume data so the SAO store does not grow without
 * bound. Every method returns the number of rows removed (or that would be, in
 * dry-run) and deletes for real only when `$dryRun` is false — retention frees
 * disk, so it force-deletes rather than soft-deletes.
 *
 * DB foreign-key cascades do the child cleanup: pruning a signal drops its
 * occurrences, pruning a ticket drops its comments/change-refs/links/releases.
 * Closed-project pruning keeps the project, its environments and releases as
 * anagraphic data and removes only the heavy tails.
 */
final readonly class RetentionService
{
    public function pruneSignalOccurrences(int $days, bool $dryRun): int
    {
        return $this->purge(
            SignalOccurrence::query()->where('occurred_at', '<', $this->cutoff($days))->toBase(),
            $dryRun,
        );
    }

    public function pruneIngestEvents(int $days, bool $dryRun): int
    {
        return $this->purge(
            IngestEvent::query()->where('created_at', '<', $this->cutoff($days))->toBase(),
            $dryRun,
        );
    }

    /**
     * Prune deployments older than the window, keeping the most recent per
     * (project, environment) so the version census stays valid.
     */
    public function pruneDeployments(int $days, bool $dryRun): int
    {
        // The key goes through the grammar and the alias is no reserved word: `id` is one on Db2.
        $latest = Deployment::query();
        $latestIds = $latest
            ->selectRaw('MAX(' . $latest->getGrammar()->wrap($latest->getModel()->getKeyName()) . ') as latest_id')
            ->groupBy('project_id', 'environment_id')
            ->pluck('latest_id')
            ->all();

        $query = Deployment::query()
            ->where('started_at', '<', $this->cutoff($days))
            ->when($latestIds !== [], fn (Builder $builder): Builder => $builder->whereNotIn('id', $latestIds));

        return $this->purge($query->toBase(), $dryRun);
    }

    /**
     * Purge the heavy data of projects deactivated longer than the grace period.
     * The project, its environments and releases are kept as anagraphic records.
     */
    public function pruneClosedProjects(int $days, bool $dryRun): int
    {
        $projectIds = Project::query()
            ->where('is_active', false)
            ->where('updated_at', '<', $this->cutoff($days))
            ->pluck('id')
            ->all();

        if ($projectIds === []) {
            return 0;
        }

        $removed = 0;
        $removed += $this->purge(\Modules\SAO\Models\Signal::query()->whereIn('project_id', $projectIds)->toBase(), $dryRun);
        $removed += $this->purge(IngestEvent::query()->whereIn('project_id', $projectIds)->toBase(), $dryRun);
        $removed += $this->purge(Deployment::query()->whereIn('project_id', $projectIds)->toBase(), $dryRun);
        $removed += $this->purgeTickets($projectIds, $dryRun);

        return $removed;
    }

    /**
     * Tickets are removed one by one so the media library clears each ticket's
     * attachments; FK cascade takes the comments, change refs, links and releases.
     *
     * @param  list<int>  $projectIds
     */
    private function purgeTickets(array $projectIds, bool $dryRun): int
    {
        $query = Ticket::query()->whereIn('project_id', $projectIds);

        if ($dryRun) {
            return $query->count();
        }

        $removed = 0;

        $query->chunkById(500, function (Collection $tickets) use (&$removed): void {
            foreach ($tickets as $ticket) {
                $ticket->clearMediaCollection('attachments');
                $ticket->forceDelete();
                $removed++;
            }
        });

        return $removed;
    }

    private function purge(QueryBuilder $query, bool $dryRun): int
    {
        return $dryRun ? $query->count() : $query->delete();
    }

    private function cutoff(int $days): CarbonInterface
    {
        return now()->subDays(max(0, $days));
    }
}
