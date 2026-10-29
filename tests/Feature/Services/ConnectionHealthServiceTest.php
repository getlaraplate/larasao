<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ConnectionHealth;
use Modules\SAO\Models\Connection;
use Modules\SAO\Services\ConnectionHealthService;

uses(RefreshDatabase::class);

function githubConnection(array $attributes = []): Connection
{
    return Connection::factory()->create(array_merge([
        'driver_key' => 'github',
        'base_url' => 'https://api.github.com',
        'credential' => ['token' => 'ghp_secret'],
        'capabilities' => [Capability::Issues],
    ], $attributes));
}

test('a reachable connection is recorded healthy with a check timestamp', function (): void {
    Http::fake(['*/rate_limit' => Http::response(['resources' => []], 200)]);
    $connection = githubConnection();

    $result = app(ConnectionHealthService::class)->check($connection);

    expect($result->healthy)->toBeTrue()
        ->and($connection->fresh()->health_state)->toBe(ConnectionHealth::Healthy)
        ->and($connection->fresh()->last_checked_at)->not->toBeNull();
});

test('an HTTP error is recorded unhealthy', function (): void {
    Http::fake(['*/rate_limit' => Http::response(['message' => 'Bad credentials'], 401)]);
    $connection = githubConnection();

    $result = app(ConnectionHealthService::class)->check($connection);

    expect($result->healthy)->toBeFalse()
        ->and($connection->fresh()->health_state)->toBe(ConnectionHealth::Unhealthy);
});

test('a missing credential is unhealthy without any network call', function (): void {
    Http::fake();
    $connection = githubConnection(['credential' => null, 'credential_ref' => null]);

    $result = app(ConnectionHealthService::class)->check($connection);

    expect($result->healthy)->toBeFalse()
        ->and($connection->fresh()->health_state)->toBe(ConnectionHealth::Unhealthy);

    Http::assertNothingSent();
});

test('a probe connection without a secret is checked by its driver and recorded healthy', function (): void {
    Http::fake();
    $connection = Connection::factory()->create([
        'driver_key' => 'http-version',
        'base_url' => null,
        'credential' => null,
        'credential_ref' => null,
        'capabilities' => [Capability::Probe],
    ]);

    $result = app(ConnectionHealthService::class)->check($connection);

    expect($result->healthy)->toBeTrue()
        ->and($connection->fresh()->health_state)->toBe(ConnectionHealth::Healthy);

    Http::assertNothingSent();
});

test('a probe connection naming a credential ref that does not resolve is unhealthy', function (): void {
    $connection = Connection::factory()->create([
        'driver_key' => 'http-version',
        'base_url' => null,
        'credential' => null,
        'credential_ref' => 'services.sao_probe_absent',
        'capabilities' => [Capability::Probe],
    ]);

    expect(app(ConnectionHealthService::class)->check($connection)->healthy)->toBeFalse()
        ->and($connection->fresh()->health_state)->toBe(ConnectionHealth::Unhealthy);
});
