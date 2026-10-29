<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Drivers\Support\HealthCheckResult;
use Modules\SAO\Enums\ConnectionHealth;
use Modules\SAO\Models\Connection;
use Throwable;

/**
 * Runs a connection's driver health check against the live external system and
 * records the outcome on the connection (`health_state` + `last_checked_at`).
 *
 * It is the one place that turns a stored {@see Connection} into a live probe:
 * it resolves the secret through {@see ConnectionCredentialResolver}, builds the
 * driver's {@see \Modules\SAO\Drivers\Support\ConnectionContext}, and asks the
 * driver. A driver whose schema declares no required secret is checked even when
 * the connection holds none ({@see ConnectionCredentialResolver::resolveFor()}). A
 * missing required credential, an unknown driver, or any thrown error becomes an
 * `unhealthy` result rather than an exception into the caller.
 */
final readonly class ConnectionHealthService
{
    public function __construct(
        private DriverRegistry $registry,
        private ConnectionCredentialResolver $resolver,
    ) {}

    public function check(Connection $connection): HealthCheckResult
    {
        try {
            $driver = $connection->driver($this->registry);
            $context = $connection->connectionContext($this->resolver->resolveFor($connection, $driver));
            $result = $driver->healthCheck($context);
        } catch (Throwable $exception) {
            $result = HealthCheckResult::unhealthy($exception->getMessage());
        }

        $connection->forceFill([
            'health_state' => $result->healthy ? ConnectionHealth::Healthy : ConnectionHealth::Unhealthy,
            'last_checked_at' => now(),
        ])->save();

        return $result;
    }
}
