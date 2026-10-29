<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Illuminate\Database\Eloquent\Collection;
use Modules\SAO\Data\FixStatus;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\FixPresence;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Ticket;

/**
 * Reads a ticket's fix-propagation state from persisted facts: the merged
 * pull request, the shipped release attributed to it, and which of the
 * project's environments run that release's version. Answers "already fixed
 * on dev, deploy missing" without calling any driver. Containment per
 * environment (does the running commit hold the fix?) is read from the
 * persisted {@see FixPresence} rows.
 */
final class FixStatusResolver
{
    /**
     * A ticket whose project was deleted still resolves, with no environments.
     */
    public function forTicket(Ticket $ticket, ?string $reportingEnvironment = null): FixStatus
    {
        $pullRequestMerged = $ticket->changeRefs()->mergedPullRequests()->fixes()->exists();

        $environments = $ticket->project?->environments()->orderBy('name')->get() ?? new Collection;
        [$contained, $notContained] = $this->containment($ticket, $environments);

        $shippedRelease = $ticket->releases()
            ->where('status', ReleaseStatus::Shipped->value)
            ->orderByDesc('released_at')
            ->first();

        if (! $shippedRelease instanceof Release) {
            return new FixStatus(
                pull_request_merged: $pullRequestMerged,
                fix_released: false,
                released_version: null,
                deployed_environments: [],
                missing_environments: [],
                deployed_there: $reportingEnvironment === null ? null : false,
                contained_environments: $contained,
                not_contained_environments: $notContained,
            );
        }

        $version = $shippedRelease->version;

        $deployed = $environments
            ->filter(fn (Environment $environment): bool => $environment->current_version === $version)
            ->map(fn (Environment $environment): string => $environment->name)
            ->values()
            ->all();

        $missing = $environments
            ->reject(fn (Environment $environment): bool => $environment->current_version === $version)
            ->map(fn (Environment $environment): string => $environment->name)
            ->values()
            ->all();

        return new FixStatus(
            pull_request_merged: $pullRequestMerged,
            fix_released: true,
            released_version: $version,
            deployed_environments: $deployed,
            missing_environments: $missing,
            deployed_there: $reportingEnvironment === null
                ? null
                : in_array($reportingEnvironment, $deployed, true),
            contained_environments: $contained,
            not_contained_environments: $notContained,
        );
    }

    /**
     * Which environments contain the ticket's fix, read from the presence rows of its
     * `Fixes` change refs in one query.
     *
     * Only rows whose `deployed_commit` is the environment's current commit count; a row for an
     * older commit says nothing about what runs now. Among those current rows, one `true` makes
     * the environment contained (a ticket may be fixed by several change refs and one carrying
     * the fix is enough); otherwise it is not contained when at least one row is `false`. Rows
     * that are all `null` (the VCS could not tell) or absent put it in neither list.
     *
     * @param  Collection<int, Environment>  $environments  the ticket's project environments
     * @return array{0: list<string>, 1: list<string>} contained and not contained environment names, sorted
     */
    private function containment(Ticket $ticket, Collection $environments): array
    {
        $running = $environments->filter(static fn (Environment $environment): bool => $environment->current_commit !== null);

        if ($running->isEmpty()) {
            return [[], []];
        }

        $rows = FixPresence::query()
            ->whereIn('change_ref_id', $ticket->changeRefs()->fixes()->select('id'))
            ->whereIn('environment_id', $running->modelKeys())
            ->get(['environment_id', 'deployed_commit', 'contained'])
            ->groupBy('environment_id');

        $contained = [];
        $notContained = [];

        foreach ($running as $environment) {
            $current = $rows->get($environment->getKey(), collect())
                ->filter(static fn (FixPresence $presence): bool => $presence->deployed_commit === $environment->current_commit);

            if ($current->contains(static fn (FixPresence $presence): bool => $presence->contained === true)) {
                $contained[] = $environment->name;
            } elseif ($current->contains(static fn (FixPresence $presence): bool => $presence->contained === false)) {
                $notContained[] = $environment->name;
            }
        }

        sort($contained);
        sort($notContained);

        return [$contained, $notContained];
    }
}
