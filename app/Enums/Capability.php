<?php

declare(strict_types=1);

namespace Modules\SAO\Enums;

/**
 * A family of behaviour a driver can expose and a domain service can depend on.
 *
 * Domain services depend only on these capabilities, never on a concrete driver
 * (spec §5): one GitHub connection exposes `vcs` + `issues` + `releases`,
 * Redmine only `issues`, Graylog only `logs`.
 */
enum Capability: string
{
    case Vcs = 'vcs';
    case Issues = 'issues';
    case Releases = 'releases';
    case Logs = 'logs';
    case Deploy = 'deploy';
    case Code = 'code';
    case Probe = 'probe';

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
}
