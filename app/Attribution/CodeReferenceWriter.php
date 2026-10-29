<?php

declare(strict_types=1);

namespace Modules\SAO\Attribution;

use Modules\SAO\Enums\ChangeRefRelation;
use Modules\SAO\Enums\ChangeRefType;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Ticket;

/**
 * Turns a {@see CodeReference} into persisted {@see ChangeRef} links: it extracts
 * the ticket references, resolves each key to a ticket, and upserts one change ref
 * per (ticket, type, identifier).
 *
 * Idempotent — re-processing the same commit/PR updates the existing row rather
 * than duplicating it — and monotonic on relation: a later fix upgrades an earlier
 * mention, but a mention never downgrades a fix. Keys that resolve to no ticket
 * are reported, not created. This is the transport-agnostic writer both the pull
 * scan and the PR-merge webhook feed.
 */
final readonly class CodeReferenceWriter
{
    public function __construct(private TicketReferenceExtractor $extractor) {}

    public function write(CodeReference $reference): CodeReferenceOutcome
    {
        $changeRefs = [];
        $unknownKeys = [];

        foreach ($this->extractor->extract($reference->text) as $ticketReference) {
            $ticket = Ticket::query()->where('key', $ticketReference->key)->first();

            if (! $ticket instanceof Ticket) {
                $unknownKeys[] = $ticketReference->key;

                continue;
            }

            $changeRefs[] = $this->upsert($ticket, $reference, $ticketReference->relation);
        }

        return new CodeReferenceOutcome($changeRefs, array_values(array_unique($unknownKeys)));
    }

    private function upsert(Ticket $ticket, CodeReference $reference, ChangeRefRelation $relation): ChangeRef
    {
        $changeRef = ChangeRef::query()->firstOrNew([
            'ticket_id' => $ticket->getKey(),
            'type' => $reference->type,
            'identifier' => $reference->identifier,
        ]);

        $changeRef->url = $reference->url ?? $changeRef->url;
        $changeRef->source = $reference->source ?? $changeRef->source;
        $changeRef->merged_at = $reference->mergedAt ?? $changeRef->merged_at;
        $changeRef->base_ref = $reference->baseRef ?? $changeRef->base_ref;
        $changeRef->head_ref = $reference->headRef ?? $changeRef->head_ref;
        $changeRef->commit_sha = $this->resolveCommitSha($changeRef, $reference);
        $changeRef->relation = $this->resolveRelation($changeRef, $relation);

        $changeRef->save();

        return $changeRef;
    }

    /**
     * A commit reference is its own sha; any other type takes the sha the reference
     * carries, falling back to the stored one so a re-delivery without it keeps it.
     * Stored lowercase, so it compares equal to what a VCS reports.
     */
    private function resolveCommitSha(ChangeRef $changeRef, CodeReference $reference): ?string
    {
        $sha = $reference->type === ChangeRefType::Commit
            ? $reference->identifier
            : ($reference->commitSha ?? $changeRef->commit_sha);

        return $sha === null || $sha === '' ? null : mb_strtolower($sha);
    }

    /**
     * A fix is monotonic: once a change ref fixes a ticket it stays a fix, even if
     * a later delivery only mentions it.
     */
    private function resolveRelation(ChangeRef $changeRef, ChangeRefRelation $incoming): ChangeRefRelation
    {
        if (! $changeRef->exists) {
            return $incoming;
        }

        return $changeRef->relation === ChangeRefRelation::Fixes
            ? ChangeRefRelation::Fixes
            : $incoming;
    }
}
