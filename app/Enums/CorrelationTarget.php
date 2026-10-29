<?php

declare(strict_types=1);

namespace Modules\SAO\Enums;

/**
 * The canonical field a {@see \Modules\SAO\Models\CorrelationRule} compares its
 * pattern against. `RequestHost` is the host of the canonical `request_url`.
 */
enum CorrelationTarget: string
{
    case ProjectKey = 'project_key';
    case ServerHost = 'server_host';
    case RequestHost = 'request_host';

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

    /**
     * The value this target compares, or null when the event carries none (a
     * missing field, or a request url without a host).
     *
     * @param  array<string, mixed>  $canonical
     */
    public function valueFrom(array $canonical): ?string
    {
        return match ($this) {
            self::ProjectKey => $this->string($canonical['project_key'] ?? null),
            self::ServerHost => $this->string($canonical['server_host'] ?? null),
            self::RequestHost => $this->hostOf($this->string($canonical['request_url'] ?? null)),
        };
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function hostOf(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? mb_strtolower($host) : null;
    }
}
