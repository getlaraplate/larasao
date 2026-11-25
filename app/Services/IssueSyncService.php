<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Modules\SAO\Data\ChangeContext;
use Modules\SAO\Drivers\Contracts\IssuesCapability;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Enums\SyncOutcome;
use Modules\SAO\Exceptions\UnsupportedCapabilityException;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\SyncOperation;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketLink;
use Modules\SAO\Models\TicketType;

/**
 * Reconciles a SAO ticket with its remote counterpart in the direction the
 * binding configures. Every outbound write is idempotent by a persisted key, so
 * a retry can never produce a second ticket or comment; every reason a sync
 * stops is returned explicitly rather than silently defaulted.
 */
final readonly class IssueSyncService
{
    private const string SOURCE_KEY = 'sync';

    public function __construct(
        private DriverRegistry $registry,
        private ConnectionCredentialResolver $resolver,
        private TicketCreationService $creation,
    ) {}

    /**
     * SAO ticket → remote. No-op unless the binding syncs outbound.
     */
    public function push(ProjectBinding $binding, Ticket $ticket): SyncOutcome
    {
        if (! $binding->sync_direction->syncsOutbound()) {
            return SyncOutcome::SkippedDirection;
        }

        $driver = $this->issuesDriver($binding);
        $context = $binding->bindingContext($this->resolver);

        $attributes = ['title' => $ticket->title, 'body' => $ticket->description];
        $key = $this->idempotencyKey($binding, (string) $ticket->getKey(), $attributes);

        if (SyncOperation::query()->where('idempotency_key', $key)->exists()) {
            return SyncOutcome::SkippedIdempotent;
        }

        $link = $ticket->links()->where('connection_id', $binding->connection_id)->first();

        if ($link instanceof TicketLink) {
            $driver->update($context, $link->remote_id, $attributes);
            $outcome = SyncOutcome::Updated;
        } else {
            $issue = $driver->create($context, $attributes);
            TicketLink::query()->create([
                'ticket_id' => $ticket->getKey(),
                'connection_id' => $binding->connection_id,
                'remote_id' => (string) $issue['remote_id'],
                'url' => $issue['url'] ?? null,
                'last_sync_state' => 'pushed',
            ]);
            $outcome = SyncOutcome::Created;
        }

        $this->record($binding, $key, $outcome);

        return $outcome;
    }

    /**
     * Remote issue → SAO. No-op unless the binding syncs inbound; an unmapped
     * remote status stops the sync rather than guessing a canonical category.
     */
    public function pull(ProjectBinding $binding, string $remoteId): SyncOutcome
    {
        if (! $binding->sync_direction->syncsInbound()) {
            return SyncOutcome::SkippedDirection;
        }

        $driver = $this->issuesDriver($binding);
        $issue = $driver->lookup($binding->bindingContext($this->resolver), $remoteId);

        if ($issue === null) {
            return SyncOutcome::NotFound;
        }

        return $this->reconcile($binding, $issue);
    }

    /**
     * Upsert one already-normalized remote issue into SAO. This is the primitive
     * behind {@see pull()} and the polling {@see IssueSyncPoller}: the caller has
     * already fetched (or listed) the issue, so no lookup happens here. It does
     * not re-check sync direction — the caller owns that gate. An unmapped remote
     * status stops the reconcile rather than guessing a canonical category.
     *
     * @param  array<string, mixed>  $issue
     */
    public function reconcile(ProjectBinding $binding, array $issue): SyncOutcome
    {
        $remoteId = (string) ($issue['remote_id'] ?? '');

        if ($remoteId === '') {
            return SyncOutcome::NotFound;
        }

        $remoteStatus = $issue['remote_status'] ?? null;

        if ($remoteStatus !== null && ! array_key_exists($remoteStatus, $binding->status_map ?? [])) {
            return SyncOutcome::UnmappedStatus;
        }

        return $this->upsertFromIssue($binding, $issue);
    }

    /**
     * Bulk-import one already-normalized remote issue into SAO for a migration.
     * Unlike {@see reconcile()} it does not gate on an unmapped remote status — a
     * migration brings the whole history in and every imported ticket opens at the
     * workflow's initial status. Idempotent by `TicketLink`, so re-running skips
     * what already imported. Status-based filtering (e.g. open-only) is the
     * importer's job, applied before this call.
     *
     * @param  array<string, mixed>  $issue
     */
    public function import(ProjectBinding $binding, array $issue): SyncOutcome
    {
        $remoteId = (string) ($issue['remote_id'] ?? '');

        if ($remoteId === '') {
            return SyncOutcome::NotFound;
        }

        return $this->upsertFromIssue($binding, $issue);
    }

    /**
     * Link-or-create the SAO ticket for a normalized remote issue. The shared
     * primitive behind {@see reconcile()} and {@see import()}; matches on
     * `TicketLink` by `(connection, remote_id)` so it never duplicates.
     *
     * @param  array<string, mixed>  $issue
     */
    private function upsertFromIssue(ProjectBinding $binding, array $issue): SyncOutcome
    {
        $remoteId = (string) ($issue['remote_id'] ?? '');

        $link = TicketLink::query()
            ->where('connection_id', $binding->connection_id)
            ->where('remote_id', $remoteId)
            ->first();

        if ($link instanceof TicketLink) {
            $link->ticket->fill(['title' => $issue['title'] ?? $link->ticket->title])->save();

            return SyncOutcome::Updated;
        }

        $type = TicketType::query()->whereKey($binding->config['ticket_type'] ?? null)->firstOrFail();

        $ticket = $this->creation->open($binding->project, $type, [
            'title' => $issue['title'] ?? '',
            'description' => $issue['body'] ?? null,
        ], ChangeContext::forAutomation(self::SOURCE_KEY));

        TicketLink::query()->create([
            'ticket_id' => $ticket->getKey(),
            'connection_id' => $binding->connection_id,
            'remote_id' => $remoteId,
            'url' => $issue['url'] ?? null,
            'last_sync_state' => 'pulled',
        ]);

        return SyncOutcome::Created;
    }

    private function issuesDriver(ProjectBinding $binding): IssuesCapability
    {
        $driver = $this->registry->get($binding->remoteConnection->driver_key);

        if (! $driver instanceof IssuesCapability) {
            throw UnsupportedCapabilityException::for($binding->remoteConnection->driver_key, $binding->capability);
        }

        return $driver;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function idempotencyKey(ProjectBinding $binding, string $ticketId, array $attributes): string
    {
        return hash('sha256', implode(':', [
            $binding->getKey(),
            $ticketId,
            md5((string) json_encode($attributes)),
        ]));
    }

    private function record(ProjectBinding $binding, string $key, SyncOutcome $outcome): void
    {
        SyncOperation::query()->create([
            'binding_id' => $binding->getKey(),
            'idempotency_key' => $key,
            'outcome' => $outcome,
        ]);
    }
}
