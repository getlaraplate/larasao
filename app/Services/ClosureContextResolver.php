<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Modules\SAO\Closure\ClosureContext;
use Modules\SAO\Enums\StatusCategory;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Models\Ticket;

/**
 * Assembles a {@see ClosureContext} for a ticket from persisted facts alone, so
 * the conditions that read it stay pure. `now` is a parameter, not a clock read,
 * so a policy evaluation is reproducible in a test and in a replay.
 */
final class ClosureContextResolver
{
    public function __construct(private readonly FixStatusResolver $fixStatus) {}

    public function forTicket(Ticket $ticket, ?string $reportingEnvironment = null, ?CarbonInterface $now = null): ClosureContext
    {
        $now ??= CarbonImmutable::now();
        $fix = $this->fixStatus->forTicket($ticket, $reportingEnvironment);

        return new ClosureContext(
            pull_request_merged: $fix->pull_request_merged,
            reporting_environment: $reportingEnvironment,
            last_recurrence_at: $this->lastRecurrenceAt($ticket, $reportingEnvironment),
            fix_released: $fix->fix_released,
            fix_deployed_there: $fix->deployed_there ?? false,
            resolved_at: $this->resolvedAt($ticket),
            is_internal: $ticket->isInternal(),
            now: $now,
        );
    }

    /**
     * The most recent occurrence of any of the ticket's signals in the reporting
     * environment. Scoping to the environment is what keeps "no recurrence" from
     * counting silence on an unrelated stage as evidence.
     */
    private function lastRecurrenceAt(Ticket $ticket, ?string $reportingEnvironment): ?CarbonInterface
    {
        $query = SignalOccurrence::query()
            ->whereIn('signal_id', $ticket->signals()->select('id'))
            ->when(
                $reportingEnvironment !== null,
                static fn (Builder $q): Builder => $q->where('environment', $reportingEnvironment),
            );

        $occurrence = $query->orderByDesc('occurred_at')->first();

        return $occurrence instanceof SignalOccurrence ? $occurrence->occurred_at : null;
    }

    /**
     * When the ticket entered the resolved category. SAO does not persist a
     * dedicated resolved timestamp, so the current status' category decides:
     * a resolved (fixed-but-unconfirmed) ticket reports its last change time.
     */
    private function resolvedAt(Ticket $ticket): ?CarbonInterface
    {
        if ($ticket->status?->category !== StatusCategory::Resolved) {
            return null;
        }

        return $ticket->updated_at;
    }
}
