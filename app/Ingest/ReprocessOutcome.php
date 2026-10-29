<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Modules\SAO\Enums\IngestStatus;

/**
 * What reprocessing one {@see \Modules\SAO\Models\IngestEvent} did, or on a dry
 * run would do: the status before and after, the rule that won (if any), and
 * whether the row was written. An ineligible event comes back unchanged with
 * `applied` false; a dry run always has `applied` false.
 */
final readonly class ReprocessOutcome
{
    public function __construct(
        public int $eventId,
        public IngestStatus $before,
        public IngestStatus $after,
        public ?string $winningRule,
        public bool $applied,
    ) {}
}
