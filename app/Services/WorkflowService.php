<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Support\PermissionName;
use Modules\SAO\Data\ChangeContext;
use Modules\SAO\Exceptions\TransitionNotAllowedException;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketStatus;
use Modules\SAO\Models\TicketType;
use Modules\SAO\Models\WorkflowScheme;
use Modules\SAO\Models\WorkflowTransition;
use RuntimeException;

/**
 * The only path to a ticket status change.
 *
 * Enforcement lives here rather than in the UI because the API and phase 2's
 * automation move tickets too, and a rule that only the interface honours is
 * not a rule.
 */
final class WorkflowService
{
    /**
     * @return Collection<int, WorkflowTransition>
     */
    public function availableTransitions(Ticket $ticket): Collection
    {
        $scheme = $this->schemeFor(
            $ticket->project()->firstOrFail(),
            $ticket->type()->firstOrFail(),
        );

        return $scheme->transitions()
            ->where('from_status_id', $ticket->ticket_status_id)
            ->get();
    }

    public function openingStatusFor(Project $project, TicketType $type): TicketStatus
    {
        $scheme = $this->schemeFor($project, $type);
        $initial = $scheme->initialTransition();

        if ($initial === null) {
            throw new RuntimeException(
                "Workflow scheme \"{$scheme->name}\" declares no creation transition, so a new ticket has no status to start in.",
            );
        }

        return TicketStatus::findOrFail($initial->to_status_id);
    }

    /**
     * The project-level override first, then the type's own scheme.
     */
    public function schemeFor(Project $project, TicketType $type): WorkflowScheme
    {
        $pivot = $project->ticketTypes()
            ->wherePivot('ticket_type_id', $type->getKey())
            ->first()?->pivot;

        $override_id = $pivot?->workflow_scheme_id;

        if ($override_id !== null) {
            return WorkflowScheme::findOrFail($override_id);
        }

        return $type->scheme()->firstOrFail();
    }

    public function transition(Ticket $ticket, TicketStatus $to, ChangeContext $context): Ticket
    {
        $permitted = $this->availableTransitions($ticket)
            ->firstWhere('to_status_id', $to->getKey());

        if (! $permitted instanceof WorkflowTransition) {
            // Claiming an override is not the same as holding one. Without the
            // permission the claim is worth nothing, which is what stops the
            // escape hatch from becoming the normal way through.
            if (! $context->hasOverride() || ! Gate::allows(PermissionName::forClass(Ticket::class, 'transition_override'))) {
                throw TransitionNotAllowedException::between($ticket, $to);
            }
        } elseif ($permitted->required_permission !== null && ! Gate::allows($permitted->required_permission)) {
            throw TransitionNotAllowedException::between($ticket, $to);
        }

        $ticket->ticket_status_id = $to->id;
        $ticket->save();

        return $ticket;
    }
}
