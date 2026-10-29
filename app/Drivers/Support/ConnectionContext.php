<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Support;

/**
 * The resolved, persistence-agnostic view of a connection handed to a driver.
 *
 * Drivers operate on this value object, never on the `Connection` Eloquent model:
 * the domain layer resolves the base URL and (via ConnectionCredentialResolver)
 * the secret, then passes them in. Keeps `app/Drivers` free of database concerns.
 */
final readonly class ConnectionContext
{
    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $config  The connection's non-secret configuration.
     */
    public function __construct(
        public ?string $baseUrl,
        public array $credentials,
        public array $config = [],
    ) {}
}
