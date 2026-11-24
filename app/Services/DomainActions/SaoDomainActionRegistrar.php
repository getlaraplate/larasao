<?php

declare(strict_types=1);

namespace Modules\SAO\Services\DomainActions;

use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\User;
use Modules\Core\Services\Crud\DomainActionRegistry;
use Modules\SAO\Data\ChangeContext;
use Modules\SAO\Ingest\IngestReplayService;
use Modules\SAO\Models\ClosureAudit;
use Modules\SAO\Models\ClosurePolicy;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\OwnershipSuggestion;
use Modules\SAO\Models\SourceProfile;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketStatus;
use Modules\SAO\Models\WorkflowTransition;
use Modules\SAO\Services\ClosureApplicationService;
use Modules\SAO\Services\ConnectionHealthService;
use Modules\SAO\Services\OwnershipSuggestionApplier;
use Modules\SAO\Services\WorkflowService;

/**
 * Maps SAO domain actions onto the services that implement them.
 *
 * Handlers stay thin: state guards and permissions live in
 * {@see \Modules\SAO\Policies\SaoModelPolicy} and the services, exactly as they
 * do for the Filament pages that drive the same code. Each handler is the
 * sanctioned single path to its operation — a status change goes through
 * {@see WorkflowService}, never a raw `ticket_status_id` write.
 */
final class SaoDomainActionRegistrar
{
    public function register(DomainActionRegistry $registry): void
    {
        $registry->register(Ticket::class, 'transition', static fn (Ticket $record, array $payload, User $user): Ticket => resolve(WorkflowService::class)->transition(
            $record,
            TicketStatus::query()->whereKey($payload['to_status_id'])->firstOrFail(),
            ChangeContext::forUser($user),
        ));

        $registry->register(Ticket::class, 'transitions', static function (Ticket $record, array $payload, User $user): array {
            $transitions = resolve(WorkflowService::class)->availableTransitions($record);
            $labels = TicketStatus::query()
                ->whereIn('id', $transitions->pluck('to_status_id')->all())
                ->pluck('name', 'id');

            return $transitions
                ->map(static fn (WorkflowTransition $transition): array => [
                    'to_status_id' => $transition->to_status_id,
                    'label' => $labels[$transition->to_status_id] ?? null,
                    'allowed' => $transition->required_permission === null
                        || Gate::allows($transition->required_permission),
                ])
                ->values()
                ->all();
        });

        $registry->register(Ticket::class, 'close', static fn (Ticket $record, array $payload, User $user): ?ClosureAudit => resolve(ClosureApplicationService::class)->apply(
            $record,
            ClosurePolicy::query()->whereKey($payload['policy_id'])->firstOrFail(),
            isset($payload['reporting_environment']) ? (string) $payload['reporting_environment'] : null,
        ));

        $registry->register(OwnershipSuggestion::class, 'accept', static fn (OwnershipSuggestion $record, array $payload, User $user): Ticket => resolve(OwnershipSuggestionApplier::class)->apply($record));

        $registry->register(Connection::class, 'health', static function (Connection $record, array $payload, User $user): array {
            $result = resolve(ConnectionHealthService::class)->check($record);

            return [
                'healthy' => $result->healthy,
                'detail' => $result->detail,
                'health_state' => $record->health_state->value,
                'last_checked_at' => $record->last_checked_at?->toIso8601String(),
            ];
        });

        $registry->register(IngestEvent::class, 'replay', static function (IngestEvent $record, array $payload, User $user): array {
            $profile_id = $payload['profile_id'] ?? $record->source_profile_id;

            return resolve(IngestReplayService::class)->dryRun($record, SourceProfile::query()->whereKey($profile_id)->firstOrFail());
        });
    }
}
