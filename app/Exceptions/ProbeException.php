<?php

declare(strict_types=1);

namespace Modules\SAO\Exceptions;

use RuntimeException;

/**
 * A version probe was refused or got an unusable answer. The message is a short
 * reason meant to be printed; it never carries a credential or a response body.
 */
final class ProbeException extends RuntimeException
{
    public static function invalidBaseUrl(string $reason): self
    {
        return new self("Environment base URL refused: {$reason}.");
    }

    public static function malformedAuthHeader(): self
    {
        return new self('The auth header credential must be "Name: value" with a valid header name.');
    }

    public static function unreachable(): self
    {
        return new self('The request failed or timed out.');
    }

    public static function badStatus(int $status): self
    {
        return new self("The endpoint answered HTTP {$status}.");
    }

    public static function notJsonObject(): self
    {
        return new self('The response is not a JSON object.');
    }

    public static function versionMissing(string $path): self
    {
        return new self("The response carries no usable version at [{$path}].");
    }
}
