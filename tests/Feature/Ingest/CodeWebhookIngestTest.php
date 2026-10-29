<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Support\CrudApiExposure;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ChangeRefRelation;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\Ticket;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CrudApiExposure::enable();
});

function sao_code_connection(string $secret = 'shared'): Connection
{
    return Connection::factory()->create([
        'driver_key' => 'webhook-code',
        'capabilities' => [Capability::Code],
        'credential' => ['secret' => $secret],
        'base_url' => null,
    ]);
}

function sao_bind_code(Connection $connection, Project $project): ProjectBinding
{
    return ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Code,
        'remote_identifier' => 'acme/app',
    ]);
}

function sao_code_url(Connection $connection): string
{
    return '/api/v1/webhooks/' . $connection->getKey();
}

test('a signed merged-PR delivery records a fix change ref on the ticket', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $body = ['identifier' => 'pr-7', 'type' => 'pull_request', 'text' => "Fixes {$ticket->key}"];

    $response = $this->postJson(sao_code_url($connection), $body, [
        'X-Code-Token' => 'shared',
        'X-Delivery-Id' => 'del-1',
    ]);

    $response->assertStatus(202)->assertJson(['result' => 'code-recorded']);

    $changeRef = ChangeRef::query()->where('ticket_id', $ticket->id)->sole();

    expect($changeRef->identifier)->toBe('pr-7')
        ->and($changeRef->relation)->toBe(ChangeRefRelation::Fixes)
        ->and(IngestEvent::query()->where('connection_id', $connection->id)->where('status', 'ingested')->count())->toBe(1)
        ->and(IngestEvent::query()->sole()->pipeline)->toBe(IngestPipeline::Code);
});

test('a forged code delivery is rejected and stores nothing', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $response = $this->postJson(sao_code_url($connection), ['identifier' => 'pr-7', 'text' => "Fixes {$ticket->key}"], [
        'X-Code-Token' => 'wrong',
        'X-Delivery-Id' => 'del-1',
    ]);

    $response->assertStatus(401);

    expect(ChangeRef::query()->count())->toBe(0)
        ->and(IngestEvent::query()->count())->toBe(0);
});

test('a re-delivered code event is recorded once', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $body = ['identifier' => 'pr-7', 'text' => "Closes {$ticket->key}"];
    $headers = ['X-Code-Token' => 'shared', 'X-Delivery-Id' => 'del-1'];

    $this->postJson(sao_code_url($connection), $body, $headers)->assertStatus(202);
    $this->postJson(sao_code_url($connection), $body, $headers)->assertStatus(202);

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and(IngestEvent::query()->where('connection_id', $connection->id)->where('status', 'ingested')->count())->toBe(1);
});

test('a code delivery to a connection with no code binding is accepted and discarded', function (): void {
    $connection = sao_code_connection();

    $response = $this->postJson(sao_code_url($connection), ['identifier' => 'pr-7', 'text' => 'Fixes SAO-1'], [
        'X-Code-Token' => 'shared',
        'X-Delivery-Id' => 'del-1',
    ]);

    $response->assertStatus(202)->assertJson(['result' => 'no-code-binding']);

    expect(ChangeRef::query()->count())->toBe(0)
        ->and(IngestEvent::query()->where('status', 'discarded')->count())->toBe(1);
});
