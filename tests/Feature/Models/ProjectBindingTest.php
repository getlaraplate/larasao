<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\SyncDirection;
use Modules\SAO\Exceptions\UnsupportedCapabilityException;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Tests\Support\Drivers\FakeIssuesDriver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(DriverRegistry::class)->register(new FakeIssuesDriver);
});

function issuesConnection(array $attributes = []): Connection
{
    return Connection::factory()->create(array_merge([
        'driver_key' => 'fake',
        'capabilities' => [Capability::Issues],
    ], $attributes));
}

test('a binding persists and carries direction and maps', function (): void {
    $binding = ProjectBinding::factory()->create([
        'project_id' => Project::factory(),
        'connection_id' => issuesConnection(),
        'capability' => Capability::Issues,
        'remote_identifier' => 'proj-1',
        'sync_direction' => SyncDirection::Outbound,
        'status_map' => ['Done' => 'resolved'],
        'priority_map' => ['H' => 'high'],
    ])->fresh();

    expect($binding->capability)->toBe(Capability::Issues)
        ->and($binding->sync_direction)->toBe(SyncDirection::Outbound)
        ->and($binding->status_map)->toBe(['Done' => 'resolved'])
        ->and($binding->priority_map)->toBe(['H' => 'high']);
});

test('the project-connection-capability-remote tuple is unique', function (): void {
    $project = Project::factory()->create();
    $connection = issuesConnection();

    $attributes = [
        'project_id' => $project->id,
        'connection_id' => $connection->id,
        'capability' => Capability::Issues,
        'remote_identifier' => 'proj-1',
    ];

    ProjectBinding::factory()->create($attributes);

    expect(fn (): ProjectBinding => ProjectBinding::factory()->create($attributes))
        ->toThrow(QueryException::class);
});

test('bindingContext resolves credentials and carries the binding config', function (): void {
    $connection = issuesConnection(['credential' => ['token' => 't'], 'base_url' => 'https://tracker.test']);

    $binding = ProjectBinding::factory()->create([
        'project_id' => Project::factory(),
        'connection_id' => $connection->id,
        'capability' => Capability::Issues,
        'remote_identifier' => 'proj-1',
        'status_map' => ['Done' => 'resolved'],
        'config' => ['project' => 5],
    ]);

    $context = $binding->bindingContext(app(ConnectionCredentialResolver::class));

    expect($context->baseUrl())->toBe('https://tracker.test')
        ->and($context->credentials())->toBe(['token' => 't'])
        ->and($context->remoteIdentifier)->toBe('proj-1')
        ->and($context->statusMap)->toBe(['Done' => 'resolved'])
        ->and($context->config)->toBe(['project' => 5]);
});

test('a binding whose connection was deleted refuses to run and names the connection', function (): void {
    $connection = issuesConnection(['credential' => ['token' => 't']]);

    $binding = ProjectBinding::factory()->create([
        'project_id' => Project::factory(),
        'connection_id' => $connection->id,
        'capability' => Capability::Issues,
    ]);

    $connection->delete();

    expect(fn (): mixed => $binding->fresh()?->bindingContext(app(ConnectionCredentialResolver::class)))
        ->toThrow(ModelNotFoundException::class, 'No query results for model [' . Connection::class . '] ' . $connection->id);
});

test('a binding cannot declare a capability its connection does not expose', function (): void {
    $connection = issuesConnection(); // exposes only Issues

    expect(fn (): ProjectBinding => ProjectBinding::factory()->create([
        'project_id' => Project::factory(),
        'connection_id' => $connection->id,
        'capability' => Capability::Releases,
        'remote_identifier' => 'proj-1',
    ]))->toThrow(UnsupportedCapabilityException::class);
});
