<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Support;

/**
 * Comparing a commit sha the caller holds with one a provider returns.
 *
 * A caller may hold an abbreviated sha (the 7 characters of a log line) while the
 * provider answers with the full 40; git treats an abbreviation as a prefix, so the
 * comparison is a case-insensitive prefix match of the provider's sha.
 */
trait ReadsCommitShas
{
    /**
     * The shortest abbreviation worth asking a provider about.
     */
    private const int MIN_SHA_LENGTH = 7;

    /**
     * Whether the string can be a commit sha at all: hexadecimal, at least
     * {@see self::MIN_SHA_LENGTH} characters, so no request is wasted on a ref name
     * or a truncated value.
     */
    private static function isUsableSha(string $sha): bool
    {
        return mb_strlen($sha) >= self::MIN_SHA_LENGTH && preg_match('/\A[0-9a-f]+\z/i', $sha) === 1;
    }

    /**
     * Whether `$full`, a sha a provider returned, names the commit `$sha`, which the
     * caller may have abbreviated. An identical pair matches.
     */
    private static function sameCommit(string $sha, string $full): bool
    {
        return $sha !== '' && str_starts_with(mb_strtolower($full), mb_strtolower($sha));
    }
}
