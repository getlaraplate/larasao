<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ConnectionHealth;
use Modules\SAO\Exceptions\UnsupportedCapabilityException;
use Modules\SAO\Models\Connection;
use Modules\SAO\Tests\Support\Drivers\FakeIssuesDriver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(DriverRegistry::class)->register(new FakeIssuesDriver);
});

test('a connection persists with its capabilities as enum instances', function (): void {
    $fresh = Connection::factory()->create([
        'driver_key' => 'fake',
        'capabilities' => [Capability::Issues],
    ])->fresh();

    expect($fresh->capabilities->all())->toContain(Capability::Issues)
        ->and($fresh->health_state)->toBe(ConnectionHealth::Unknown);
});

test('the credential column is encrypted at rest', function (): void {
    $fresh = Connection::factory()->create([
        'driver_key' => 'fake',
        'capabilities' => [Capability::Issues],
        'credential' => ['token' => 'supersecret'],
    ])->fresh();

    expect($fresh->credential)->toBe(['token' => 'supersecret'])
        ->and($fresh->getRawOriginal('credential'))->not->toContain('supersecret');
});

test('declaring a capability the driver does not expose is rejected', function (): void {
    expect(fn (): Connection => Connection::factory()->create([
        'driver_key' => 'fake',
        'capabilities' => [Capability::Issues, Capability::Vcs],
    ]))->toThrow(UnsupportedCapabilityException::class);
});

test('the config column persists as a plain (unencrypted) array', function (): void {
    $fresh = Connection::factory()->create([
        'driver_key' => 'fake',
        'capabilities' => [Capability::Issues],
        'config' => ['project' => 5, 'ticket_type' => 2],
    ])->fresh();

    expect($fresh->config)->toBe(['project' => 5, 'ticket_type' => 2])
        // Config is non-secret: stored as plain JSON, unlike the encrypted credential.
        ->and($fresh->getRawOriginal('config'))->toContain('project');
});

test('a connection builds a connection context from resolved credentials', function (): void {
    $connection = Connection::factory()->create([
        'driver_key' => 'fake',
        'base_url' => 'https://tracker.test',
        'capabilities' => [Capability::Issues],
    ]);

    $context = $connection->connectionContext(['token' => 'abc']);

    expect($context->baseUrl)->toBe('https://tracker.test')
        ->and($context->credentials)->toBe(['token' => 'abc'])
        ->and($context->config)->toBe([]);
});

test('the connection context carries the connection config', function (): void {
    $connection = Connection::factory()->create([
        'driver_key' => 'fake',
        'base_url' => 'https://tracker.test',
        'capabilities' => [Capability::Issues],
        'config' => ['path' => '/status'],
    ]);

    expect($connection->connectionContext([])->config)->toBe(['path' => '/status']);
});

test('a connection resolves its driver from the registry', function (): void {
    $connection = Connection::factory()->create([
        'driver_key' => 'fake',
        'capabilities' => [Capability::Issues],
    ]);

    expect($connection->driver(app(DriverRegistry::class))->key())->toBe('fake');
});
