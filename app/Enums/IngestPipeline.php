<?php

declare(strict_types=1);

namespace Modules\SAO\Enums;

/**
 * Which ingest path recorded an `IngestEvent`. Reprocessing replays an event
 * through the path that recorded it, so the marker decides what can be replayed:
 * only the pipelines that turn a payload into a signal.
 */
enum IngestPipeline: string
{
    case Profile = 'profile';
    case Logs = 'logs';
    case Deploy = 'deploy';
    case Code = 'code';

    public static function validationRule(): string
    {
        return 'in:' . implode(',', self::values());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isReprocessable(): bool
    {
        return match ($this) {
            self::Profile, self::Logs => true,
            self::Deploy, self::Code => false,
        };
    }
}
