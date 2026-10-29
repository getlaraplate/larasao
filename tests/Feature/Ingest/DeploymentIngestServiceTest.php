<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\SAO\Drivers\Support\DeployEvent;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\DeploymentStatus;
use Modules\SAO\Events\DeploymentRecorded;
use Modules\SAO\Ingest\DeploymentIngestService;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Deployment;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(DeploymentIngestService::class);
});

test('a succeeded deploy is recorded, resolves the environment and advances the census', function (): void {
    Event::fake([DeploymentRecorded::class]);

    $project = Project::factory()->create();

    $deployment = $this->service->ingest($project, new DeployEvent(
        version: '1.4.0',
        status: DeploymentStatus::Succeeded,
        environmentName: 'production',
    ));

    $environment = Environment::query()->where('project_id', $project->id)->where('name', 'production')->first();

    expect($deployment->exists)->toBeTrue()
        ->and($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($environment)->not->toBeNull()
        ->and($environment->current_version)->toBe('1.4.0')
        ->and($environment->last_seen_at)->not->toBeNull();

    Event::assertDispatched(DeploymentRecorded::class, fn (DeploymentRecorded $e): bool => $e->wasRecentlyCreated === true
        && $e->deployment->is($deployment));
});

test('a started deploy leaves finished_at null and does not touch the census', function (): void {
    $project = Project::factory()->create();

    $deployment = $this->service->ingest($project, new DeployEvent(
        version: '2.0.0',
        status: DeploymentStatus::Started,
        environmentName: 'production',
    ));

    $environment = Environment::query()->where('project_id', $project->id)->where('name', 'production')->first();

    expect($deployment->finished_at)->toBeNull()
        ->and($environment->current_version)->toBeNull();
});

test('a rolled-back deploy is terminal but never asserted as running', function (): void {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create(['name' => 'production', 'current_version' => '1.3.0']);

    $deployment = $this->service->ingest($project, new DeployEvent(
        version: '1.4.0',
        status: DeploymentStatus::RolledBack,
        environmentName: 'production',
    ));

    $environment = Environment::query()->where('project_id', $project->id)->where('name', 'production')->first();

    expect($deployment->finished_at)->not->toBeNull()
        ->and($environment->current_version)->toBe('1.3.0');
});

test('re-delivering the same (connection, external id) advances the same deployment', function (): void {
    $project = Project::factory()->create();
    $connection = Connection::factory()->create([
        'driver_key' => 'graylog',
        'capabilities' => [Capability::Logs],
    ]);

    $first = $this->service->ingest($project, new DeployEvent(
        version: '3.1.0',
        status: DeploymentStatus::Started,
        environmentName: 'production',
        externalId: 'deploy-abc',
    ), $connection);

    $second = $this->service->ingest($project, new DeployEvent(
        version: '3.1.0',
        status: DeploymentStatus::Succeeded,
        environmentName: 'production',
        externalId: 'deploy-abc',
    ), $connection);

    expect($second->getKey())->toBe($first->getKey())
        ->and(Deployment::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and($second->refresh()->status)->toBe(DeploymentStatus::Succeeded)
        ->and($second->finished_at)->not->toBeNull();
});

test('a resolved release is attributed to the deployment', function (): void {
    $project = Project::factory()->create();
    $release = Release::factory()->for($project)->create(['version' => '4.2.0']);

    $deployment = $this->service->ingest($project, new DeployEvent(
        version: '4.2.0',
        status: DeploymentStatus::Succeeded,
        environmentName: 'production',
    ));

    expect($deployment->release_id)->toBe($release->id);
});

test('a version with no matching release records the deployment with a null release', function (): void {
    $project = Project::factory()->create();

    $deployment = $this->service->ingest($project, new DeployEvent(
        version: '9.9.9',
        status: DeploymentStatus::Succeeded,
        environmentName: 'production',
    ));

    expect($deployment->release_id)->toBeNull()
        ->and($deployment->version)->toBe('9.9.9');
});

test('meta is persisted as structured data', function (): void {
    $project = Project::factory()->create();

    $deployment = $this->service->ingest($project, new DeployEvent(
        version: '1.0.0',
        status: DeploymentStatus::Succeeded,
        environmentName: 'canary',
        meta: ['canary_weight' => 20, 'sha' => 'abc123'],
    ));

    expect($deployment->refresh()->meta)->toBe(['canary_weight' => 20, 'sha' => 'abc123']);
});

test('a succeeded deploy carrying a sha and a ref stores them as the environment commit and branch', function (): void {
    $project = Project::factory()->create();

    $this->service->ingest($project, new DeployEvent(
        version: '1.4.0',
        status: DeploymentStatus::Succeeded,
        environmentName: 'production',
        meta: ['sha' => 'abc1234', 'ref' => 'main'],
    ));

    $environment = Environment::query()->where('project_id', $project->id)->where('name', 'production')->sole();

    expect($environment->current_commit)->toBe('abc1234')
        ->and($environment->current_branch)->toBe('main');
});

test('a succeeded deploy without a sha or ref keeps the stored commit and branch', function (): void {
    $project = Project::factory()->create();
    Environment::factory()->for($project)->create([
        'name' => 'production',
        'current_commit' => 'abc1234',
        'current_branch' => 'main',
    ]);

    $this->service->ingest($project, new DeployEvent(
        version: '1.5.0',
        status: DeploymentStatus::Succeeded,
        environmentName: 'production',
        meta: ['sha' => 42, 'ref' => ['x']],
    ));

    $environment = Environment::query()->where('project_id', $project->id)->where('name', 'production')->sole();

    expect($environment->current_version)->toBe('1.5.0')
        ->and($environment->current_commit)->toBe('abc1234')
        ->and($environment->current_branch)->toBe('main');
});
