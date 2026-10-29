<?php

declare(strict_types=1);

namespace Modules\SAO\Enums;

/**
 * How a {@see \Modules\SAO\Models\CorrelationRule} compares its pattern with the
 * event value. Both sides are lowercased and the whole string must match.
 * `Wildcard` knows one metacharacter, `*` (any sequence, anywhere); every other
 * character, the dot included, is literal.
 */
enum MatchType: string
{
    case Exact = 'exact';
    case Wildcard = 'wildcard';

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

    public function matches(string $pattern, string $value): bool
    {
        $pattern = mb_strtolower($pattern);
        $value = mb_strtolower($value);

        if ($this === self::Exact) {
            return $pattern === $value;
        }

        $regex = str_replace('\*', '.*', preg_quote($pattern, '#'));

        return preg_match('#^' . $regex . '$#suD', $value) === 1;
    }
}
