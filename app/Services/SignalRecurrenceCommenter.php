<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Modules\SAO\Data\ChangeContext;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketComment;

/**
 * Tells the ticket of a signal that the error came back: one system comment per cooldown, summing up the
 * occurrences recorded since the previous such comment (or since the ticket was created).
 *
 * It runs inside the ingest transaction, under the lock on the signal row, so two concurrent occurrences of
 * one signal cannot both pass the cooldown. Its only effects are database rows on the ticket comment's own
 * connection (the comment and the version rows Core writes for it, no notification, broadcast, job or
 * tracker sync), so a rolled back or retried ingest leaves nothing behind and the comment is decided again
 * from scratch. The cooldown is measured against `now()`, not the occurrence time: replaying many old events
 * of one signal posts at most one comment.
 */
final readonly class SignalRecurrenceCommenter
{
    /**
     * The source key of the comments about one signal; the cooldown is read back from it.
     */
    public static function sourceKey(Signal $signal): string
    {
        return 'signal-recurrence:' . $signal->getKey();
    }

    /**
     * @param  Signal  $signal  The signal the occurrence was just recorded on.
     * @param  SignalOccurrence  $occurrence  The occurrence that was just created.
     * @return TicketComment|null The comment posted, or null when none is due.
     */
    public function afterOccurrence(Signal $signal, SignalOccurrence $occurrence): ?TicketComment
    {
        if (! (bool) config('sao.signals.recurrence_comment.enabled', true) || $signal->ticket_id === null) {
            return null;
        }

        $ticket = Ticket::query()->with('status')->find($signal->ticket_id);

        if (! $ticket instanceof Ticket || $ticket->status->category->isTerminal()) {
            return null;
        }

        $sourceKey = self::sourceKey($signal);
        $previous = $ticket->comments()
            ->where('source_key', $sourceKey)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($previous instanceof TicketComment && $previous->created_at->greaterThan(now()->subMinutes($this->cooldownMinutes()))) {
            return null;
        }

        $span = SignalOccurrence::query()
            ->where('signal_id', $signal->getKey())
            ->where('id', '<=', $occurrence->getKey())
            ->when(
                $previous instanceof TicketComment,
                static fn ($query) => $query->where('created_at', '>', $previous->created_at),
                static fn ($query) => $query->where('created_at', '>=', $ticket->created_at),
            )
            ->get(['occurred_at', 'environment', 'affected_version']);

        if ($span->isEmpty()) {
            return null;
        }

        return TicketComment::postFor(
            $ticket,
            $this->body($span, $previous instanceof TicketComment),
            ChangeContext::forAutomation($sourceKey),
        );
    }

    /**
     * The read site clamps the setting so that 0 never means a comment on every occurrence.
     */
    private function cooldownMinutes(): int
    {
        return max(1, (int) config('sao.signals.recurrence_comment.cooldown_minutes', 60));
    }

    /**
     * @param  Collection<int, SignalOccurrence>  $span
     */
    private function body(Collection $span, bool $sincePreviousComment): string
    {
        $count = $span->count();

        return implode("\n", [
            sprintf(
                'This error recurred %d %s since %s.',
                $count,
                $count === 1 ? 'time' : 'times',
                $sincePreviousComment ? 'the previous recurrence comment' : 'the ticket was created',
            ),
            'First occurrence in this span: ' . $this->formatted($span->min('occurred_at')),
            'Last occurrence in this span: ' . $this->formatted($span->max('occurred_at')),
            'Environments: ' . $this->distinct($span->pluck('environment')),
            'Versions: ' . $this->distinct($span->pluck('affected_version')),
        ]);
    }

    private function formatted(?CarbonInterface $time): string
    {
        return $time?->copy()->utc()->format('Y-m-d H:i:s') . ' UTC';
    }

    /**
     * @param  Collection<int, mixed>  $values
     */
    private function distinct(Collection $values): string
    {
        $distinct = $values
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique()
            ->sort()
            ->values();

        return $distinct->isEmpty() ? 'none recorded' : $distinct->implode(', ');
    }
}
