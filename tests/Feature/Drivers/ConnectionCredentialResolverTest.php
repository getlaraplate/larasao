<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Modules\SAO\Drivers\External\GitHubDriver;
use Modules\SAO\Drivers\External\HttpVersionDriver;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ConnectionHealth;
use Modules\SAO\Exceptions\MissingCredentialException;
use Modules\SAO\Models\Connection;

/**
 * Build an unsaved connection so the resolver can be tested without the
 * driver-registry save invariant. Encryption and config need the framework, so
 * this is a feature test.
 */
function unsavedConnection(array $attributes): Connection
{
    return (new Connection)->forceFill(array_merge([
        'driver_key' => 'fake',
        'name' => 'c',
        'capabilities' => [Capability::Issues],
        'health_state' => ConnectionHealth::Unknown,
    ], $attributes));
}

test('the env credential_ref wins and the column is ignored', function (): void {
    Config::set('sao.test_secret', ['token' => 'from-env']);

    $connection = unsavedConnection([
        'credential_ref' => 'sao.test_secret',
        'credential' => ['token' => 'from-db'],
    ]);

    expect((new ConnectionCredentialResolver)->resolve($connection))->toBe(['token' => 'from-env']);
});

test('the decrypted column is returned when no ref is set', function (): void {
    $connection = unsavedConnection([
        'credential_ref' => null,
        'credential' => ['token' => 'from-db'],
    ]);

    expect((new ConnectionCredentialResolver)->resolve($connection))->toBe(['token' => 'from-db']);
});

test('a connection with neither source throws', function (): void {
    $connection = unsavedConnection(['credential_ref' => null, 'credential' => null]);

    expect(fn (): array => (new ConnectionCredentialResolver)->resolve($connection))
        ->toThrow(MissingCredentialException::class);
});

test('a ref that resolves to nothing throws', function (): void {
    $connection = unsavedConnection(['credential_ref' => 'sao.absent', 'credential' => null]);

    expect(fn (): array => (new ConnectionCredentialResolver)->resolve($connection))
        ->toThrow(MissingCredentialException::class);
});

test('the resolver does not mutate the connection', function (): void {
    Config::set('sao.test_secret', ['token' => 'from-env']);
    $connection = unsavedConnection(['credential_ref' => 'sao.test_secret']);

    (new ConnectionCredentialResolver)->resolve($connection);

    expect($connection->credential_ref)->toBe('sao.test_secret')
        ->and($connection->getAttribute('credential'))->toBeNull();
});

test('for a driver without a required secret a connection holding none resolves to no credentials', function (): void {
    $connection = unsavedConnection(['credential_ref' => null, 'credential' => null]);

    expect((new ConnectionCredentialResolver)->resolveFor($connection, app(HttpVersionDriver::class)))->toBe([]);
});

test('for a driver without a required secret a named ref must still resolve', function (): void {
    $connection = unsavedConnection(['credential_ref' => 'sao.absent', 'credential' => null]);

    expect(fn (): array => (new ConnectionCredentialResolver)->resolveFor($connection, app(HttpVersionDriver::class)))
        ->toThrow(MissingCredentialException::class);
});

test('for a driver with a required secret a connection holding none still throws', function (): void {
    $connection = unsavedConnection(['credential_ref' => null, 'credential' => null]);

    expect(fn (): array => (new ConnectionCredentialResolver)->resolveFor($connection, app(GitHubDriver::class)))
        ->toThrow(MissingCredentialException::class);
});

test('for a driver without a required secret a stored credential is still returned', function (): void {
    $connection = unsavedConnection(['credential_ref' => null, 'credential' => ['auth_header' => 'X-Probe-Token: abc']]);

    expect((new ConnectionCredentialResolver)->resolveFor($connection, app(HttpVersionDriver::class)))
        ->toBe(['auth_header' => 'X-Probe-Token: abc']);
});
