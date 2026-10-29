<?php

declare(strict_types=1);

namespace Modules\SAO\Authorization;

use Modules\Core\Authorization\Contracts\DeclaresPermissions;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\OwnershipSuggestion;
use Modules\SAO\Models\Ticket;
use Override;

/**
 * SAO domain permissions.
 *
 * Only what the domain adds on top of CRUD: moving a ticket through its
 * workflow, overriding a workflow that would otherwise deadlock the work,
 * closing a ticket by applying a closure policy, accepting an ownership
 * suggestion, probing a connection's health, replaying a stored ingest event
 * and reprocessing one that did not become a signal. All but `transition_override`
 * and `reprocess` back a SPA-facing domain action in
 * {@see \Modules\SAO\Services\DomainActions\SaoDomainActionRegistrar}; the
 * override is read straight from a gate by {@see \Modules\SAO\Services\WorkflowService},
 * and `reprocess` by the reprocess actions of the ingest event backoffice list.
 *
 * Nothing is declared here that no code reads. A permission with no consumer is
 * worse than a missing one: an administrator grants or withholds it and believes
 * the operation is governed, while the operation runs on some other verb. That
 * is why assigning a ticket is not on this list — the assignee is a column like
 * any other, written through `update`, and the one sanctioned assignment path is
 * accepting an ownership suggestion, which carries its own permission.
 *
 * The generic verbs are deliberately absent. `permission:refresh` already
 * generates `select`, `insert`, `update`, `delete` and the rest for every table
 * it manages, so declaring them here would only duplicate them, and a declared
 * name is exempt from the command's own cleanup branches — which would let this
 * file override a Core decision without saying so.
 */
final class SAOPermissions implements DeclaresPermissions
{
    #[Override]
    public static function operations(): array
    {
        return [
            Ticket::class => ['transition', 'transition_override', 'close'],
            OwnershipSuggestion::class => ['accept'],
            Connection::class => ['health'],
            IngestEvent::class => ['replay', 'reprocess'],
        ];
    }

    #[Override]
    public static function excludedModels(): array
    {
        return [];
    }
}
