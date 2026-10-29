<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\SAO\Enums\SignalState;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Services\VersionCensusService;

/**
 * Turns a received error into a signal: it resolves the group key, opens the
 * project's signal for that key or recurs the existing one, and records an
 * occurrence. The group key is comparable across projects, so the same error in
 * two projects yields two signals (one per project) that share a key.
 */
final readonly class SignalIngestService
{
    public function __construct(
        private GroupKeyResolver $groupKeyResolver,
        private VersionCensusService $versionCensus,
    ) {}

    /**
     * Runs in a transaction on the signal's connection with the signal row locked, so two concurrent
     * occurrences of one group serialize on it: the count is incremented in SQL, and whatever is decided
     * from the signal's state inside the transaction sees the other occurrence's write.
     *
     * @param  array<string, mixed>  $payload
     * @param  CarbonInterface|null  $occurredAt  When the error originally happened; defaults to now. A replayed occurrence passes its original time: it widens the seen window both ways but never reopens a resolved signal, since an old event says nothing about the error being back.
     */
    public function ingest(Project $project, array $payload, ?CarbonInterface $occurredAt = null): Signal
    {
        $isReplay = $occurredAt instanceof CarbonInterface;
        $occurredAt ??= now();
        $groupKey = $this->groupKeyResolver->resolve($payload);

        return (new Signal)->getConnection()->transaction(function () use ($project, $payload, $occurredAt, $isReplay, $groupKey): Signal {
            $signal = $this->lockedSignal($project, $groupKey)
                ?? $this->createSignal($project, $groupKey, $payload, $occurredAt);

            // Loop protection, layer 2: past the per-group cap within the window the
            // signal stops recording occurrences, so a fast-looping error cannot
            // flood the store. The signal itself still exists and stays visible. A
            // signal another ingest created first is an existing one and is checked.
            if (! $signal->wasRecentlyCreated && $this->rateLimitReached($signal)) {
                return $signal;
            }

            $this->recordOccurrenceOn($signal, $occurredAt, $isReplay);

            $census = $this->versionCensus->record(
                $project->getKey(),
                is_string($payload['version'] ?? null) ? $payload['version'] : null,
            );

            SignalOccurrence::query()->create([
                'signal_id' => $signal->getKey(),
                'environment' => is_string($payload['environment'] ?? null) ? $payload['environment'] : null,
                'context' => is_array($payload['context'] ?? null) ? $payload['context'] : null,
                'affected_version' => $census->affectedVersion,
                'affected_release_id' => $census->release?->getKey(),
                'occurred_at' => $occurredAt,
            ]);

            return $signal;
        });
    }

    private function lockedSignal(Project $project, string $groupKey): ?Signal
    {
        return Signal::query()
            ->where('project_id', $project->getKey())
            ->where('group_key', $groupKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Creates the signal with no occurrence yet. The insert runs in a savepoint: when a concurrent ingest
     * created the same signal after the lookup, the unique key on (project_id, group_key) refuses this one,
     * the savepoint is rolled back without aborting the enclosing transaction, and the winner's row is read
     * under the lock instead.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createSignal(Project $project, string $groupKey, array $payload, CarbonInterface $occurredAt): Signal
    {
        try {
            return (new Signal)->getConnection()->transaction(static fn (): Signal => Signal::query()->create([
                'project_id' => $project->getKey(),
                'group_key' => $groupKey,
                'algo_version' => is_numeric($payload['algo_version'] ?? null) ? (int) $payload['algo_version'] : 1,
                'state' => SignalState::Open,
                'occurrence_count' => 0,
                'first_seen_at' => $occurredAt,
                'last_seen_at' => $occurredAt,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->lockedSignal($project, $groupKey) ?? throw $exception;
        }
    }

    /**
     * Counts one occurrence on the locked signal: the count is incremented in SQL
     * (`occurrence_count = occurrence_count + 1`) together with the seen window and
     * a reopen, in one update, and the model is left in sync with the row.
     */
    private function recordOccurrenceOn(Signal $signal, CarbonInterface $occurredAt, bool $isReplay): void
    {
        if ($signal->state === SignalState::Resolved && ! $isReplay) {
            // The error came back: a resolved signal reopens on a live recurrence.
            $signal->state = SignalState::Open;
        }

        if ($signal->first_seen_at === null || $occurredAt->lessThan($signal->first_seen_at)) {
            $signal->first_seen_at = $occurredAt;
        }

        if ($signal->last_seen_at === null || $occurredAt->greaterThan($signal->last_seen_at)) {
            $signal->last_seen_at = $occurredAt;
        }

        if ($signal->usesTimestamps()) {
            $signal->updateTimestamps();
        }

        $changes = $signal->getDirty();
        $signal->increment('occurrence_count', 1, $changes);
        $signal->syncOriginalAttributes(array_keys($changes));
    }

    private function rateLimitReached(Signal $signal): bool
    {
        $max = (int) config('sao.signals.max_occurrences_per_window', 1000);
        $windowMinutes = max(1, (int) config('sao.signals.window_minutes', 60));

        if ($max <= 0) {
            return false;
        }

        $recent = $signal->occurrences()
            ->where('occurred_at', '>=', now()->subMinutes($windowMinutes))
            ->count();

        return $recent >= $max;
    }
}
