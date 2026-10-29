<?php

declare(strict_types=1);

namespace Modules\SAO\Policies;

use Illuminate\Database\Eloquent\Model;
use Modules\Core\Models\Permission;
use Modules\Core\Models\User;
use Modules\Core\Support\PermissionName;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\OwnershipSuggestion;
use Modules\SAO\Models\Ticket;

/**
 * Authorizes SAO domain actions on the internal `/app` surface.
 *
 * Each method pairs an intrinsic type guard with the seeded
 * `{connection}.{table}.{action}` permission, mirroring
 * {@see \Modules\MES\Policies\MesModelPolicy}. The deeper state rules stay in
 * the services (a workflow transition is validated by {@see \Modules\SAO\Services\WorkflowService},
 * a closure by its policy conditions), so this policy only gates who may ask.
 * Generic CRUD verbs keep going through the authorization service, not here.
 */
final class SaoModelPolicy
{
    public function transition(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'transition', static fn (Model $record): bool => $record instanceof Ticket);
    }

    /**
     * Reading the moves a ticket may make next is gated by the same
     * `transition` permission that performs them: only someone allowed to move
     * a ticket needs to know where it may go.
     */
    public function transitions(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'transition', static fn (Model $record): bool => $record instanceof Ticket);
    }

    public function close(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'close', static fn (Model $record): bool => $record instanceof Ticket);
    }

    public function accept(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'accept', static fn (Model $record): bool => $record instanceof OwnershipSuggestion);
    }

    public function health(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'health', static fn (Model $record): bool => $record instanceof Connection);
    }

    public function replay(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'replay', static fn (Model $record): bool => $record instanceof IngestEvent);
    }

    public function reprocess(User $user, Model $record): bool
    {
        return $this->allowsDomainAction($user, $record, 'reprocess', static fn (Model $record): bool => $record instanceof IngestEvent);
    }

    /**
     * @param  callable(Model): bool  $state_allows
     */
    private function allowsDomainAction(User $user, Model $record, string $operation, callable $state_allows): bool
    {
        if (! $state_allows($record)) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $this->hasPermission($user, $record, $operation);
    }

    private function hasPermission(User $user, Model $record, string $operation): bool
    {
        $permission = PermissionName::forModel($record, $operation);

        if (! Permission::query()->where('name', $permission)->exists()) {
            return false;
        }

        $guard = config('auth.defaults.guard');

        return $user->hasPermissionTo($permission, is_string($guard) ? $guard : 'web');
    }
}
