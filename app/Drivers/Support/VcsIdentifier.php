<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Support;

/**
 * The validation every untrusted commit sha and branch name passes before it is
 * stored: a change ref's commit, a log occurrence's commit and branch, a deploy
 * event's `sha` and `ref`. A value that fails is treated as absent, so the caller
 * keeps what it already had instead of storing garbage or failing on a column.
 */
final class VcsIdentifier
{
    /**
     * The length of the string columns a branch is stored in.
     */
    private const int MAX_BRANCH_LENGTH = 255;

    /**
     * The sha, lowercased, when the value is 7 to 64 hexadecimal characters
     * (an abbreviated or full SHA-1 or SHA-256); otherwise null.
     */
    public static function sha(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A[0-9a-f]{7,64}\z/i', $value) !== 1) {
            return null;
        }

        return mb_strtolower($value);
    }

    /**
     * The branch when the value is a non-empty string that fits its column;
     * otherwise null.
     */
    public static function branch(mixed $value): ?string
    {
        if (! is_string($value) || $value === '' || mb_strlen($value) > self::MAX_BRANCH_LENGTH) {
            return null;
        }

        return $value;
    }
}
