<?php

declare(strict_types=1);

namespace Modules\SAO\Enums;

use Modules\Core\Enums\Concerns\HasModuleTablesUtils;

enum SAOTables: string
{
    use HasModuleTablesUtils;

    case Projects = 'sao_projects';
    case TicketStatuses = 'sao_ticket_statuses';
    case WorkflowSchemes = 'sao_workflow_schemes';
    case WorkflowTransitions = 'sao_workflow_transitions';
    case TicketTypes = 'sao_ticket_types';
    case ProjectTicketTypes = 'sao_project_ticket_types';
    case Tickets = 'sao_tickets';
    case TicketComments = 'sao_ticket_comments';
    case Labels = 'sao_labels';
    case TicketLabel = 'sao_ticket_label';
    case TicketWatchers = 'sao_ticket_watchers';
    case TicketRelations = 'sao_ticket_relations';
    case SavedFilters = 'sao_saved_filters';
    case Signals = 'sao_signals';
    case SignalOccurrences = 'sao_signal_occurrences';
    case SignalAliases = 'sao_signal_aliases';
    case SourceProfiles = 'sao_source_profiles';
    case IngestEvents = 'sao_ingest_events';
    case ChangeRefs = 'sao_change_refs';
    case Releases = 'sao_releases';
    case ReleaseTags = 'sao_release_tags';
    case TicketReleases = 'sao_ticket_releases';
    case Environments = 'sao_environments';
    case Deployments = 'sao_deployments';
    case ClosurePolicies = 'sao_closure_policies';
    case ClosureAudits = 'sao_closure_audits';
    case OwnershipSuggestions = 'sao_ownership_suggestions';
    case ContributorIdentities = 'sao_contributor_identities';
    case Connections = 'sao_connections';
    case ProjectBindings = 'sao_project_bindings';
    case TicketLinks = 'sao_ticket_links';
    case SyncOperations = 'sao_sync_operations';
    case ImportRuns = 'sao_import_runs';
    case CorrelationRules = 'sao_correlation_rules';
    case FixPresences = 'sao_fix_presences';
}
