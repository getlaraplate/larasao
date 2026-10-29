<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Modules\SAO\Models\Connection;
use Modules\SAO\Models\SourceProfile;

/**
 * Where an event came from, as far as correlation is concerned: the connection
 * that received it, the profile that normalized it, and whether a rule may only
 * route to projects bound to that connection for `logs` (the driver path).
 */
final readonly class CorrelationScope
{
    public function __construct(
        public ?int $connectionId,
        public ?int $sourceProfileId,
        public bool $logsBindingRequired,
    ) {}

    /**
     * The generic path: a normalized payload, any project can be a target.
     */
    public static function forProfile(?Connection $connection, SourceProfile $profile): self
    {
        $connectionId = $connection?->getKey();

        return new self(
            $connectionId === null ? null : (int) $connectionId,
            (int) $profile->getKey(),
            false,
        );
    }

    /**
     * The driver path: events unpacked by the connection's own driver, which can
     * only route to projects with a `logs` binding on that connection.
     */
    public static function forLogs(Connection $connection): self
    {
        return new self((int) $connection->getKey(), null, true);
    }
}
