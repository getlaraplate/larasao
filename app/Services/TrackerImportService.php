<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Modules\SAO\Data\ChangeContext;
use Modules\SAO\Data\TrackerImportReport;
use Modules\SAO\Drivers\Contracts\IssueHistoryCapability;
use Modules\SAO\Drivers\Contracts\IssuesCapability;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Drivers\Support\ReadsExternalPayloads;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ImportRunStatus;
use Modules\SAO\Enums\ImportScope;
use Modules\SAO\Enums\StatusCategory;
use Modules\SAO\Enums\SyncOutcome;
use Modules\SAO\Models\ImportRun;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketComment;
use Modules\SAO\Models\TicketLink;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Imports an external tracker's issue history into SAO — the "switch to Laraplate,
 * bring your history" migration. It walks every page of the `issues` driver's list
 * and upserts each issue through {@see IssueSyncService::import()} (idempotent by
 * `TicketLink`, so re-running is safe), independent of the binding's ongoing sync
 * direction — a migration is a deliberate one-time pull.
 *
 * Progress is persisted on an {@see ImportRun} per (binding, scope): the driver's
 * next-page cursor and the running counts are saved after every page, so an import
 * that spans thousands of pages — or that is killed by a crash, a redeploy or a
 * requeue — resumes from the exact page it stopped on rather than re-walking from
 * the start. A run flips to {@see ImportRunStatus::Completed} only when the walk is
 * exhausted; a finished import re-run opens a fresh run.
 *
 * With {@see ImportScope::Open} it skips issues whose remote status maps to a
 * terminal category through the binding's `status_map`; an unmapped status is kept
 * as open so nothing still active is dropped.
 *
 * When the driver also implements {@see IssueHistoryCapability}, each imported
 * ticket gets its comment thread and attachments too — both idempotent by the
 * tracker's stable remote ids, so a resumed or re-run import never duplicates them.
 */
final readonly class TrackerImportService
{
    use ReadsExternalPayloads;

    private const int MAX_PAGES_PER_INVOCATION = 5000;

    private const string COMMENT_SOURCE_PREFIX = 'tracker-import:comment:';

    private const string ATTACHMENT_COLLECTION = 'attachments';

    public function __construct(
        private DriverRegistry $registry,
        private ConnectionCredentialResolver $resolver,
        private IssueSyncService $sync,
    ) {}

    public function import(ProjectBinding $binding, ImportScope $scope): TrackerImportReport
    {
        if ($binding->capability !== Capability::Issues) {
            return TrackerImportReport::skipped();
        }

        $driver = $this->registry->get($binding->remoteConnection->driver_key);

        if (! $driver instanceof IssuesCapability) {
            return TrackerImportReport::skipped();
        }

        $context = $binding->bindingContext($this->resolver);
        $history = $driver instanceof IssueHistoryCapability ? $driver : null;
        $run = $this->runFor($binding, $scope);

        $cursor = $run->cursor;
        $pagesThisInvocation = 0;
        $truncated = false;

        do {
            if ($pagesThisInvocation >= self::MAX_PAGES_PER_INVOCATION) {
                $truncated = true;

                break;
            }

            $page = $driver->list($context, $cursor);
            $pagesThisInvocation++;
            $run->pages++;

            foreach ($page->items as $issue) {
                if ($scope === ImportScope::Open && $this->isTerminal($binding, $issue)) {
                    $run->filtered_count++;

                    continue;
                }

                $outcome = $this->sync->import($binding, $issue);

                match ($outcome) {
                    SyncOutcome::Created => $run->created_count++,
                    SyncOutcome::Updated => $run->updated_count++,
                    default => $run->skipped_count++,
                };

                if ($history !== null && $outcome !== SyncOutcome::NotFound) {
                    [$comments, $attachments] = $this->importHistory($history, $context, $binding, $issue);
                    $run->comment_count += $comments;
                    $run->attachment_count += $attachments;
                }
            }

            $cursor = $page->nextCursor;
            $run->cursor = $cursor;
            $run->truncated = false;
            $run->save();
        } while ($cursor !== null);

        $run->truncated = $truncated;
        $run->status = $truncated ? ImportRunStatus::Running : ImportRunStatus::Completed;
        $run->save();

        return new TrackerImportReport(
            processed: true,
            created: $run->created_count,
            updated: $run->updated_count,
            filtered: $run->filtered_count,
            skipped: $run->skipped_count,
            comments: $run->comment_count,
            attachments: $run->attachment_count,
            pages: $run->pages,
            truncated: $run->truncated,
        );
    }

    /**
     * The resumable run for this (binding, scope): the still-`Running` one if an
     * earlier invocation was interrupted, otherwise a fresh run. A completed run is
     * never reused, so re-importing a finished migration re-walks deterministically.
     */
    private function runFor(ProjectBinding $binding, ImportScope $scope): ImportRun
    {
        return ImportRun::query()->firstOrCreate([
            'binding_id' => $binding->getKey(),
            'scope' => $scope,
            'status' => ImportRunStatus::Running,
        ]);
    }

    /**
     * Import one issue's comments and attachments onto its SAO ticket, both
     * idempotent by the tracker's stable remote ids.
     *
     * @param  array<string, mixed>  $issue
     * @return array{0: int, 1: int} [comments imported, attachments imported]
     */
    private function importHistory(IssueHistoryCapability $history, BindingContext $context, ProjectBinding $binding, array $issue): array
    {
        $remoteId = self::stringOr($issue['remote_id'] ?? null);

        $link = TicketLink::query()
            ->where('connection_id', $binding->connection_id)
            ->where('remote_id', $remoteId)
            ->first();

        if (! $link instanceof TicketLink) {
            return [0, 0];
        }

        $ticket = $link->ticket;

        $comments = 0;

        foreach ($history->comments($context, $remoteId) as $comment) {
            $comments += $this->importComment($ticket, $comment) ? 1 : 0;
        }

        $attachments = 0;

        foreach ($history->attachments($context, $remoteId) as $attachment) {
            $attachments += $this->importAttachment($ticket, $attachment) ? 1 : 0;
        }

        return [$comments, $attachments];
    }

    /**
     * Post one imported comment, keyed by the remote comment id so a re-run adds
     * it at most once. An empty id or body is ignored.
     *
     * @param  array{remote_id?: string, body?: string, author?: string|null}  $comment
     */
    private function importComment(Ticket $ticket, array $comment): bool
    {
        $remoteId = self::stringOr($comment['remote_id'] ?? null);
        $body = self::stringOr($comment['body'] ?? null);

        if ($remoteId === '' || $body === '') {
            return false;
        }

        $sourceKey = self::COMMENT_SOURCE_PREFIX . $remoteId;

        if ($ticket->comments()->where('source_key', $sourceKey)->exists()) {
            return false;
        }

        TicketComment::postFor($ticket, $body, ChangeContext::forAutomation($sourceKey));

        return true;
    }

    /**
     * Store one imported attachment, keyed by the remote attachment id in a media
     * custom property so a re-run adds it at most once. An empty id or filename is
     * ignored; the driver has already downloaded the bytes.
     *
     * @param  array{remote_id?: string, filename?: string, contents?: string}  $attachment
     */
    private function importAttachment(Ticket $ticket, array $attachment): bool
    {
        $remoteId = self::stringOr($attachment['remote_id'] ?? null);
        $filename = self::stringOr($attachment['filename'] ?? null);

        if ($remoteId === '' || $filename === '') {
            return false;
        }

        $alreadyStored = $ticket->getMedia(self::ATTACHMENT_COLLECTION)
            ->contains(static fn (Media $media): bool => $remoteId === self::stringOr($media->getCustomProperty('remote_id')));

        if ($alreadyStored) {
            return false;
        }

        $ticket->addMediaFromString(self::stringOr($attachment['contents'] ?? null))
            ->usingFileName($filename)
            ->withCustomProperties(['remote_id' => $remoteId])
            ->toMediaCollection(self::ATTACHMENT_COLLECTION);

        return true;
    }

    /**
     * Whether the issue's remote status maps to a terminal canonical category. A
     * missing or unmapped status is treated as non-terminal (open), so open-only
     * never drops a still-active issue.
     *
     * @param  array<string, mixed>  $issue
     */
    private function isTerminal(ProjectBinding $binding, array $issue): bool
    {
        $remoteStatus = $issue['remote_status'] ?? null;

        if (! is_string($remoteStatus)) {
            return false;
        }

        $category = StatusCategory::tryFrom(($binding->status_map ?? [])[$remoteStatus] ?? '');

        return $category?->isTerminal() ?? false;
    }
}
