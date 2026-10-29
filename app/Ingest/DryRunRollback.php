<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use RuntimeException;

/**
 * Control flow, not an error: thrown by {@see IngestReprocessService} at the
 * end of a dry run to roll back the transaction the real reprocess ran in, and
 * caught by the service itself outside it. It carries the outcome the run
 * would have had, so a dry run decides with the same code as a real one.
 *
 * @internal
 */
final class DryRunRollback extends RuntimeException
{
    public function __construct(public readonly ReprocessOutcome $outcome)
    {
        parent::__construct('Dry run rolled back.');
    }
}
