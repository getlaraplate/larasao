<?php

declare(strict_types=1);

namespace Modules\SAO\Console;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Ingest\IngestReprocessService;
use Modules\SAO\Ingest\ReprocessOutcome;
use Modules\SAO\Models\IngestEvent;

/**
 * Replay stored ingest events that produced no signal through the pipeline that
 * recorded them, after a rule, profile or binding was fixed (see
 * {@see IngestReprocessService}). Without an event id it takes every eligible
 * event (`profile` or `logs`, discarded, uncorrelated or failed), narrowed by
 * `--status`, `--connection` and `--since`. `--dry-run` reports what each event
 * would become and writes nothing. Unlike `sao:ingest:replay`, which inspects
 * one event against a chosen profile, this updates the events.
 */
final class ReprocessIngestEventsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sao:ingest:reprocess
        {event? : The IngestEvent id to reprocess; omit to reprocess every eligible event}
        {--status= : Only events with this status (discarded, uncorrelated or failed)}
        {--connection= : Only events received on this Connection id}
        {--since= : Only events received at or after this date}
        {--dry-run : Report what each event would become without writing anything}';

    /**
     * @var string
     */
    protected $description = 'Reprocess ingest events that produced no signal <fg=bright-red>(🎫 Modules\SAO)</fg=bright-red>';

    public function handle(IngestReprocessService $service): int
    {
        $query = IngestEvent::query();
        $event_id = $this->argument('event');

        if ($event_id !== null) {
            if (! IngestEvent::query()->whereKey($event_id)->exists()) {
                $this->error("No ingest event with id [{$event_id}].");

                return self::FAILURE;
            }

            $query->whereKey($event_id);
        } else {
            $query->whereIn('pipeline', array_values(array_filter(IngestPipeline::cases(), static fn (IngestPipeline $pipeline): bool => $pipeline->isReprocessable())));
        }

        $status_option = $this->option('status');

        if ($status_option !== null) {
            $status = IngestStatus::tryFrom((string) $status_option);

            if (! $status instanceof IngestStatus) {
                $this->error("Unknown status [{$status_option}]; use one of: " . implode(', ', IngestStatus::values()) . '.');

                return self::FAILURE;
            }

            $query->where('status', $status);
        } elseif ($event_id === null) {
            $query->whereIn('status', IngestReprocessService::eligibleStatuses());
        }

        $connection_option = $this->option('connection');

        if ($connection_option !== null) {
            if (! ctype_digit((string) $connection_option)) {
                $this->error("The --connection value [{$connection_option}] is not a connection id.");

                return self::FAILURE;
            }

            $query->where('connection_id', (int) $connection_option);
        }

        $since_option = $this->option('since');

        if ($since_option !== null) {
            try {
                $since = Carbon::parse((string) $since_option);
            } catch (InvalidFormatException) {
                $this->error("The --since value [{$since_option}] is not a valid date.");

                return self::FAILURE;
            }

            $query->where('created_at', '>=', $since);
        }

        $dry_run = (bool) $this->option('dry-run');
        $outcomes = $service->reprocessMany($query, $dry_run);

        foreach ($outcomes as $outcome) {
            $this->line($this->describe($outcome, $dry_run));
        }

        $count = $outcomes->count();

        if ($dry_run) {
            $this->info("Dry run: {$count} event(s) checked, nothing was written.");
        } else {
            $applied = $outcomes->filter(static fn (ReprocessOutcome $outcome): bool => $outcome->applied)->count();
            $this->info("{$count} event(s) checked, {$applied} reprocessed.");
        }

        return self::SUCCESS;
    }

    private function describe(ReprocessOutcome $outcome, bool $dryRun): string
    {
        $label = match (true) {
            $outcome->applied => 'reprocessed',
            $dryRun && $outcome->before !== $outcome->after => 'would change',
            $dryRun => 'would stay',
            default => 'skipped',
        };

        return sprintf(
            '#%d %s -> %s %s [%s]',
            $outcome->eventId,
            $outcome->before->value,
            $outcome->after->value,
            $outcome->winningRule ?? '-',
            $label,
        );
    }
}
