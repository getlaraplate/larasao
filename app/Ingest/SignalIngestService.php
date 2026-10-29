<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Modules\SAO\Drivers\Support\VcsIdentifier;
use Modules\SAO\Enums\SignalState;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Services\DeployCensusService;
use Modules\SAO\Services\SignalRecurrenceCommenter;
use Modules\SAO\Services\VersionCensusService;

/**
 * Turns a received error into a signal: it resolves the group key, opens the
 * project's signal for that key or recurs the existing one, and records an
 * occurrence. The group key is comparable across projects, so the same error in
 * two projects yields two signals (one per project) that share a key.
 */
final readonly class SignalIngestService
{
    /**
     * Attempts of the ingest transaction: the first, and two retries after a deadlock or a lock timeout.
     */
    private const int TRANSACTION_ATTEMPTS = 3;

    /**
     * How long an occurrence that would not change an environment's census leaves it unwritten.
     */
    private const int CENSUS_REFRESH_SECONDS = 60;

    public function __construct(
        private GroupKeyResolver $groupKeyResolver,
        private VersionCensusService $versionCensus,
        private DeployCensusService $deployCensus,
        private SignalRecurrenceCommenter $recurrenceCommenter,
    ) {}

    /**
     * Runs in a transaction on the signal's connection with the signal row locked, so two concurrent
     * occurrences of one group serialize on it: the count is incremented in SQL, and whatever is decided
     * from the signal's state inside the transaction sees the other occurrence's write.
     *
     * The transaction is attempted up to {@see self::TRANSACTION_ATTEMPTS} times: when three or more
     * ingests create one new signal at once, the losers of the unique key all hold a shared lock on the
     * winner's row and then ask for the exclusive one, so InnoDB picks a deadlock victim. Laravel retries
     * the whole attempt on such a concurrency error, and the retry finds the signal and waits on its lock.
     * Laravel retries only an outermost transaction: nested in another one (reprocessing) the error is
     * rethrown to that caller.
     *
     * @param  array<string, mixed>  $payload
     * @param  CarbonInterface|null  $occurredAt  When the error originally happened; defaults to now. A replayed occurrence passes its original time: it widens the seen window both ways but never reopens a resolved signal, since an old event says nothing about the error being back.
     */
    public function ingest(Project $project, array $payload, ?CarbonInterface $occurredAt = null): Signal
    {
        $isReplay = $occurredAt instanceof CarbonInterface;
        $occurredAt ??= now();
        $groupKey = $this->groupKeyResolver->resolve($payload);

        return (new Signal)->getConnection()->transaction(
            fn (): Signal => $this->recordIn($project, $payload, $occurredAt, $isReplay, $groupKey),
            self::TRANSACTION_ATTEMPTS,
        );
    }

    /**
     * The body of one ingest attempt. It reads and builds every model it touches, and changes nothing outside
     * the transaction, so a retried attempt starts from what the database holds after the rollback. The
     * recurrence comment it may post is a database write too ({@see SignalRecurrenceCommenter}).
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordIn(Project $project, array $payload, CarbonInterface $occurredAt, bool $isReplay, string $groupKey): Signal
    {
        $signal = $this->existingSignal($project, $groupKey)
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

        if (! $isReplay) {
            $this->observeEnvironment($project, $payload, $census->release);
        }

        $occurrence = SignalOccurrence::query()->create([
            'signal_id' => $signal->getKey(),
            'environment' => is_string($payload['environment'] ?? null) ? $payload['environment'] : null,
            'context' => is_array($payload['context'] ?? null) ? $payload['context'] : null,
            'affected_version' => $census->affectedVersion,
            'affected_release_id' => $census->release?->getKey(),
            'occurred_at' => $occurredAt,
        ]);

        // Still inside the transaction and under the signal lock: the cooldown check and the comment are
        // serialized per signal, and both are plain database writes that a rollback or a retry discards.
        $this->recurrenceCommenter->afterOccurrence($signal, $occurrence);

        return $signal;
    }

    /**
     * Feeds the deploy census from a live occurrence: when the occurrence's version resolved to a release
     * of the project (the same normalization and lookup as the version census, {@see VersionCensusService})
     * and the project already has an environment of the occurrence's name, that environment is observed
     * running that release's version. A version that resolves to no release (`nightly`) writes nothing, so
     * a messy log version never stands for a release version. An unknown name creates nothing, so a host
     * name used as the fallback environment never fills the census. A replayed occurrence carries a
     * historical version and is not fed in.
     *
     * The commit counts only when it is a sha and the branch only when it fits its column
     * ({@see VcsIdentifier}); otherwise each is absent and the stored one is kept. When neither version,
     * commit nor branch would change and the environment was stamped less than
     * {@see self::CENSUS_REFRESH_SECONDS} seconds ago, nothing is written: a burst of errors does not update
     * the environment row once per occurrence inside the ingest transaction. The write is a plain database
     * update of an existing row, so a retried attempt repeats it harmlessly.
     *
     * @param  array<string, mixed>  $payload
     */
    private function observeEnvironment(Project $project, array $payload, ?Release $release): void
    {
        $name = $payload['environment'] ?? null;

        if (! $release instanceof Release || ! is_string($name) || $name === '') {
            return;
        }

        $environment = Environment::query()
            ->where('project_id', $project->getKey())
            ->where('name', $name)
            ->first();

        if (! $environment instanceof Environment) {
            return;
        }

        $version = (string) $release->version;
        $commit = VcsIdentifier::sha($payload['commit'] ?? null);
        $branch = VcsIdentifier::branch($payload['branch'] ?? null);

        if ($this->isFreshAndUnchanged($environment, $version, $commit, $branch)) {
            return;
        }

        $this->deployCensus->observe($environment, $version, $commit, $branch);
    }

    private function isFreshAndUnchanged(Environment $environment, string $version, ?string $commit, ?string $branch): bool
    {
        if ($environment->last_seen_at === null || $environment->last_seen_at->lt(now()->subSeconds(self::CENSUS_REFRESH_SECONDS))) {
            return false;
        }

        return $environment->current_version === $version
            && ($commit === null || $commit === $environment->current_commit)
            && ($branch === null || $branch === $environment->current_branch);
    }

    /**
     * Finds the signal with a plain read and only then locks the row it found, by key. A locking read on a
     * key that may not exist yet would take gap locks on the unique index (InnoDB, REPEATABLE READ), and two
     * first creations of one group would then deadlock on their inserts instead of one losing the unique key.
     */
    private function existingSignal(Project $project, string $groupKey): ?Signal
    {
        $signalId = Signal::query()
            ->where('project_id', $project->getKey())
            ->where('group_key', $groupKey)
            ->value('id');

        return $signalId === null ? null : $this->lockedSignalBy(Signal::query()->whereKey($signalId));
    }

    /**
     * @param  Builder<Signal>  $query
     */
    private function lockedSignalBy(Builder $query): ?Signal
    {
        return $query->lockForUpdate()->first();
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
            return $this->lockedSignalBy(
                Signal::query()->where('project_id', $project->getKey())->where('group_key', $groupKey),
            ) ?? throw $exception;
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
