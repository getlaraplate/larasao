<?php

declare(strict_types=1);

namespace Modules\SAO\Attribution;

use Modules\SAO\Enums\ReleaseTagKind;
use Modules\SAO\Enums\TicketReleaseState;
use Modules\SAO\Exceptions\UnstableResolutionReleaseException;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketRelease;

/**
 * The guarded write path for ticket-to-release attribution: it enforces the
 * asymmetry that a ticket is `affected` by any maturity but resolved only on
 * stable. Attaching `shipped` requires the release to carry a `stable` tag;
 * `promised` accepts an announced release; `affected` accepts any, including an
 * uncurated `observed` version. This is where the rule lives, not in the UI.
 *
 * The VCS-driven {@see ReleaseAttributionService} is compliant by construction —
 * it only ships a release after registering a stable tag — so it keeps its own
 * monotonic upsert; this attributor serves the affected and manual paths.
 */
final readonly class TicketReleaseAttributor
{
    public function attach(Ticket $ticket, Release $release, TicketReleaseState $state): TicketRelease
    {
        if ($state === TicketReleaseState::Shipped && ! $this->hasStableTag($release)) {
            throw UnstableResolutionReleaseException::make($release->id);
        }

        $ticketRelease = TicketRelease::query()->firstOrNew([
            'ticket_id' => $ticket->getKey(),
            'release_id' => $release->getKey(),
        ]);

        $ticketRelease->state = $state;
        $ticketRelease->save();

        return $ticketRelease;
    }

    private function hasStableTag(Release $release): bool
    {
        return $release->tags()
            ->where('kind', ReleaseTagKind::Stable->value)
            ->exists();
    }
}
