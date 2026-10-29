<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\Support;

use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Exceptions\MissingCredentialException;
use Modules\SAO\Models\Connection;

/**
 * The single sanctioned path from a connection to its secret (F4).
 *
 * Resolution order: a set `credential_ref` reads from config/environment and
 * wins; otherwise the decrypted `credential` column is used. The secret is
 * returned for in-memory use only — never written back to the connection and
 * never logged.
 */
final class ConnectionCredentialResolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(Connection $connection): array
    {
        $ref = $connection->credential_ref;

        if ($ref !== null && $ref !== '') {
            $value = config($ref);

            if ($value === null) {
                throw MissingCredentialException::forRef($ref);
            }

            return is_array($value) ? $value : ['value' => $value];
        }

        $credential = $connection->getAttribute('credential');

        if (is_array($credential) && $credential !== []) {
            return $credential;
        }

        throw MissingCredentialException::forConnection((string) $connection->name);
    }

    /**
     * Resolves the connection's secret as the driver needs it. When the driver's
     * schema declares no required secret ({@see DriverConfigurationSchema::credentialOptional()}),
     * a connection that holds no secret at all resolves to an empty set instead of
     * failing; a connection that names a `credential_ref` must still resolve it. Every
     * driver that declares a required secret is resolved as strictly as {@see self::resolve()}.
     *
     * @return array<string, mixed>
     */
    public function resolveFor(Connection $connection, DriverInterface $driver): array
    {
        try {
            return $this->resolve($connection);
        } catch (MissingCredentialException $exception) {
            $ref = $connection->credential_ref;

            if (($ref !== null && $ref !== '') || ! $driver->configurationSchema()->credentialOptional()) {
                throw $exception;
            }

            return [];
        }
    }
}
