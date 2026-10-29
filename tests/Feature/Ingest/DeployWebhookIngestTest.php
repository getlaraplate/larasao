<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Support\CrudApiExposure;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\DeploymentStatus;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Deployment;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CrudApiExposure::enable();
});

function sao_deploy_connection(string $secret = 'shared'): Connection
{
    return Connection::factory()->create([
        'driver_key' => 'webhook-deploy',
        'capabilities' => [Capability::Deploy],
        'credential' => ['secret' => $secret],
        'base_url' => null,
    ]);
}

function sao_bind_deploy(Connection $connection, Project $project): ProjectBinding
{
    return ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Deploy,
        'remote_identifier' => 'prod',
    ]);
}

/**
 * @return array<string, mixed>
 */
function sao_deploy_body(string $version = '1.4.0', string $status = 'succeeded', string $environment = 'production'): array
{
    return ['version' => $version, 'status' => $status, 'environment' => $environment, 'external_id' => 'dep-1'];
}

function sao_deploy_url(Connection $connection): string
{
    return '/api/v1/webhooks/' . $connection->getKey();
}

test('a signed deploy delivery records a deployment and advances the census', function (): void {
    $project = Project::factory()->create();
    $connection = sao_deploy_connection();
    sao_bind_deploy($connection, $project);

    $response = $this->postJson(sao_deploy_url($connection), sao_deploy_body(), [
        'X-Deploy-Token' => 'shared',
        'X-Delivery-Id' => 'del-1',
    ]);

    $response->assertStatus(202)->assertJson(['result' => 'deployments-recorded']);

    $deployment = Deployment::query()->where('project_id', $project->id)->firstOrFail();
    $environment = Environment::query()->where('project_id', $project->id)->where('name', 'production')->first();

    expect($deployment->version)->toBe('1.4.0')
        ->and($deployment->status)->toBe(DeploymentStatus::Succeeded)
        ->and($deployment->connection_id)->toBe($connection->id)
        ->and($environment->current_version)->toBe('1.4.0')
        ->and(IngestEvent::query()->where('connection_id', $connection->id)->where('status', 'ingested')->count())->toBe(1)
        ->and(IngestEvent::query()->sole()->pipeline)->toBe(IngestPipeline::Deploy);
});

test('a forged deploy delivery is rejected and stores nothing', function (): void {
    $project = Project::factory()->create();
    $connection = sao_deploy_connection();
    sao_bind_deploy($connection, $project);

    $response = $this->postJson(sao_deploy_url($connection), sao_deploy_body(), [
        'X-Deploy-Token' => 'wrong',
        'X-Delivery-Id' => 'del-1',
    ]);

    $response->assertStatus(401);

    expect(Deployment::query()->count())->toBe(0)
        ->and(IngestEvent::query()->count())->toBe(0);
});

test('a re-delivered deploy is recorded once', function (): void {
    $project = Project::factory()->create();
    $connection = sao_deploy_connection();
    sao_bind_deploy($connection, $project);

    $headers = ['X-Deploy-Token' => 'shared', 'X-Delivery-Id' => 'del-1'];

    $this->postJson(sao_deploy_url($connection), sao_deploy_body(), $headers)->assertStatus(202);
    $this->postJson(sao_deploy_url($connection), sao_deploy_body(), $headers)->assertStatus(202);

    expect(Deployment::query()->where('project_id', $project->id)->count())->toBe(1)
        ->and(IngestEvent::query()->where('connection_id', $connection->id)->where('status', 'ingested')->count())->toBe(1);
});

test('a deploy delivery to a connection with no deploy binding is accepted and discarded', function (): void {
    $connection = sao_deploy_connection();

    $response = $this->postJson(sao_deploy_url($connection), sao_deploy_body(), [
        'X-Deploy-Token' => 'shared',
        'X-Delivery-Id' => 'del-1',
    ]);

    $response->assertStatus(202)->assertJson(['result' => 'no-deploy-binding']);

    expect(Deployment::query()->count())->toBe(0)
        ->and(IngestEvent::query()->where('status', 'discarded')->count())->toBe(1);
});
