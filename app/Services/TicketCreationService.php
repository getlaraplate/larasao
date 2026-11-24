<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Modules\SAO\Data\ChangeContext;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketType;

/**
 * The single path that opens a ticket.
 *
 * It lives here rather than in a Filament page because the API and phase 2's
 * automation open tickets too — and because the module forbids orchestration
 * logic in a UI layer, which is what makes the headless mode real rather than
 * aspirational.
 */
final readonly class TicketCreationService
{
    public function __construct(
        private TicketKeyAllocator $allocator,
        private WorkflowService $workflow,
    ) {}

    /**
     * The opening status is asked of the workflow scheme, never defaulted: a
     * scheme declaring no creation transition fails here rather than producing a
     * ticket in a status its own workflow does not know.
     *
     * @param  array<string, mixed>  $attributes  title, description, priority, assignee_id
     */
    public function open(Project $project, TicketType $type, array $attributes, ChangeContext $context): Ticket
    {
        // Resolved before allocating, so a misconfigured scheme does not burn a
        // ticket number on its way to failing.
        $status = $this->workflow->openingStatusFor($project, $type);

        $allocated = $this->allocator->allocate($project);

        $ticket = new Ticket;
        $ticket->fill([
            'project_id' => $project->getKey(),
            'ticket_type_id' => $type->getKey(),
            'title' => $attributes['title'] ?? '',
            'description' => $attributes['description'] ?? null,
            'priority' => $attributes['priority'] ?? $ticket->priority,
            'assignee_id' => $attributes['assignee_id'] ?? null,
            'reporter_id' => $context->userId(),
        ]);

        $ticket->number = $allocated['number'];
        $ticket->key = $allocated['key'];
        $ticket->ticket_status_id = $status->id;

        $ticket->save();

        return $ticket;
    }
}
