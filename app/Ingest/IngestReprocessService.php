<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Throwable;

/**
 * Replays a stored {@see IngestEvent} that did not become a signal through the
 * pipeline that recorded it, after the operator fixed what stopped it (a new
 * correlation rule, a profile, a `logs` binding).
 *
 * Only `profile` and `logs` events whose status is `discarded`, `uncorrelated`
 * or `failed` are eligible. Each event is replayed in its own transaction on
 * the row re-read under `lockForUpdate`, with the status checked again there,
 * so two concurrent reprocesses of one row ingest it once. The same row is
 * updated and stamped `reprocessed_at`; the occurrence keeps the delivery's
 * original time. A `logs` event is replayed from its stored canonical item: the
 * signature was checked at receipt and is not checked again.
 *
 * A dry run executes the very same code and rolls it back, so what it reports
 * is what a real run would do. An exception during a real run rolls back the
 * partial work and marks the row `failed`, with the exception class as outcome.
 * Everything runs inside the {@see PipelineContext}, like the live ingest.
 */
final readonly class IngestReprocessService
{
    public function __construct(
        private WebhookIngestService $profileIngest,
        private DriverWebhookIngestService $logsIngest,
        private PipelineContext $pipeline,
    ) {}

    /**
     * The statuses a reprocess can act on: the ones that produced no signal.
     *
     * @return list<IngestStatus>
     */
    public static function eligibleStatuses(): array
    {
        return [IngestStatus::Discarded, IngestStatus::Uncorrelated, IngestStatus::Failed];
    }

    public static function isEligible(IngestEvent $event): bool
    {
        return $event->pipeline->isReprocessable() && in_array($event->status, self::eligibleStatuses(), true);
    }

    public function reprocess(IngestEvent $event, bool $dryRun = false): ReprocessOutcome
    {
        return $this->pipeline->run(fn (): ReprocessOutcome => $this->attempt($event, $dryRun));
    }

    /**
     * @param  Builder<IngestEvent>  $query
     * @return Collection<int, ReprocessOutcome>
     */
    public function reprocessMany(Builder $query, bool $dryRun = false): Collection
    {
        return $query->lazyById()
            ->map(fn (IngestEvent $event): ReprocessOutcome => $this->reprocess($event, $dryRun))
            ->values()
            ->collect();
    }

    private function attempt(IngestEvent $event, bool $dryRun): ReprocessOutcome
    {
        // getKey() is declared mixed on every Eloquent model; this is a saved
        // record with an auto-incrementing key.
        $eventId = (int) $event->getKey();
        $before = $event->status;

        try {
            return $event->getConnection()->transaction(function () use ($event, $eventId, $dryRun, &$before): ReprocessOutcome {
                $locked = IngestEvent::query()->whereKey($eventId)->lockForUpdate()->first();

                if (! $locked instanceof IngestEvent) {
                    return new ReprocessOutcome($eventId, $before, $before, $event->winning_rule, false);
                }

                $before = $locked->status;
                $outcome = $this->replay($locked, $eventId, $dryRun);

                if ($dryRun) {
                    throw new DryRunRollback($outcome);
                }

                return $outcome;
            });
        } catch (DryRunRollback $rollback) {
            return $rollback->outcome;
        } catch (Throwable $exception) {
            return $this->fail($eventId, $before, $exception, $dryRun);
        }
    }

    /**
     * Run the locked row through its pipeline, or leave it untouched when it is
     * no longer (or never was) eligible.
     */
    private function replay(IngestEvent $locked, int $eventId, bool $dryRun): ReprocessOutcome
    {
        $before = $locked->status;
        $connection = $locked->getRelationValue('connection');
        $occurredAt = $locked->created_at;

        // A logs event whose connection was deleted has nothing to correlate against.
        $replayable = self::isEligible($locked)
            && ($locked->pipeline !== IngestPipeline::Logs || $connection instanceof Connection);

        if (! $replayable) {
            return new ReprocessOutcome($eventId, $before, $before, $locked->winning_rule, false);
        }

        $locked->reprocessed_at = now();

        $processed = $locked->pipeline === IngestPipeline::Logs && $connection instanceof Connection
            ? $this->logsIngest->process($locked, $connection, $occurredAt)
            : $this->profileIngest->process($locked, $occurredAt);

        return new ReprocessOutcome($eventId, $before, $processed->status, $processed->winning_rule, ! $dryRun);
    }

    /**
     * The transaction is already rolled back here, so the failure mark is its
     * own write and survives. It applies only while the row is still eligible,
     * so it never overwrites a concurrent reprocess that succeeded. A dry run
     * writes nothing, the failure included.
     */
    private function fail(int $eventId, IngestStatus $before, Throwable $exception, bool $dryRun): ReprocessOutcome
    {
        Log::warning('sao.ingest.reprocess.failed', $this->pipeline->stamp([
            'ingest_event_id' => $eventId,
            'dry_run' => $dryRun,
            'exception' => $exception,
        ]));

        $written = ! $dryRun && IngestEvent::query()
            ->whereKey($eventId)
            ->whereIn('status', self::eligibleStatuses())
            ->update([
                'status' => IngestStatus::Failed,
                'outcome' => $exception::class,
                'reprocessed_at' => now(),
            ]) > 0;

        return new ReprocessOutcome($eventId, $before, IngestStatus::Failed, null, $written);
    }
}
