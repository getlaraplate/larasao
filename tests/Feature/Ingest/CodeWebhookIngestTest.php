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

test('a merged github pull request delivery stores its merge commit sha', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = Connection::factory()->create([
        'driver_key' => 'github-pull-request',
        'capabilities' => [Capability::Code],
        'credential' => ['secret' => 'shared'],
        'base_url' => null,
    ]);
    sao_bind_code($connection, $project);

    $body = (string) json_encode([
        'action' => 'closed',
        'pull_request' => [
            'number' => 7,
            'merged' => true,
            'merged_at' => '2026-08-23T10:00:00Z',
            'merge_commit_sha' => 'ABC123DEF4567890ABC123DEF4567890ABC12345',
            'title' => "Fixes {$ticket->key}",
            'body' => '',
            'html_url' => 'https://github.com/acme/app/pull/7',
            'base' => ['ref' => 'main'],
            'head' => ['ref' => 'fix/null-guard'],
        ],
    ]);

    $this->call('POST', sao_code_url($connection), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $body, 'shared'),
        'HTTP_X_DELIVERY_ID' => 'del-gh-1',
    ], $body)
        ->assertStatus(202)
        ->assertJson(['result' => 'code-recorded']);

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)
        ->toBe('abc123def4567890abc123def4567890abc12345');
});

test('a code delivery stores the commit sha of its row', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $this->postJson(sao_code_url($connection), [
        'identifier' => 'pr-7',
        'type' => 'pull_request',
        'text' => "Fixes {$ticket->key}",
        'commit_sha' => 'a1b2c3d',
    ], ['X-Code-Token' => 'shared', 'X-Delivery-Id' => 'del-1'])->assertStatus(202);

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)->toBe('a1b2c3d');
});

test('a code delivery with an empty commit sha stores none', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $this->postJson(sao_code_url($connection), [
        'identifier' => 'pr-7',
        'type' => 'pull_request',
        'text' => "Fixes {$ticket->key}",
        'commit_sha' => '',
    ], ['X-Code-Token' => 'shared', 'X-Delivery-Id' => 'del-1'])->assertStatus(202);

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)->toBeNull();
});

test('a re-delivery without a commit sha keeps the stored one and a new sha replaces it', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $deliver = function (array $extra, string $delivery) use ($connection, $ticket): void {
        $this->postJson(sao_code_url($connection), [
            'identifier' => 'pr-7',
            'type' => 'pull_request',
            'text' => "Fixes {$ticket->key}",
            ...$extra,
        ], ['X-Code-Token' => 'shared', 'X-Delivery-Id' => $delivery])->assertStatus(202);
    };

    $deliver(['commit_sha' => 'aaa1111'], 'del-1');
    $deliver([], 'del-2');

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)->toBe('aaa1111');

    $deliver(['commit_sha' => 'bbb2222'], 'del-3');

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)->toBe('bbb2222');
});

test('a commit delivery stores its own identifier as the commit sha', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $this->postJson(sao_code_url($connection), [
        'identifier' => 'DEADBEEF01',
        'type' => 'commit',
        'text' => "Fixes {$ticket->key}",
        'commit_sha' => 'ignored',
    ], ['X-Code-Token' => 'shared', 'X-Delivery-Id' => 'del-1'])->assertStatus(202);

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)->toBe('deadbeef01');
});

test('a code delivery with a commit sha that is not 7 to 64 hex characters keeps the stored one', function (string $invalid): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $deliver = function (string $sha, string $delivery) use ($connection, $ticket): void {
        $this->postJson(sao_code_url($connection), [
            'identifier' => 'pr-7',
            'type' => 'pull_request',
            'text' => "Fixes {$ticket->key}",
            'commit_sha' => $sha,
        ], ['X-Code-Token' => 'shared', 'X-Delivery-Id' => $delivery])
            ->assertStatus(202)
            ->assertJson(['result' => 'code-recorded']);
    };

    $deliver('aaa1111', 'del-1');
    $deliver($invalid, 'del-2');

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)->toBe('aaa1111');
})->with([
    'too short' => ['abc123'],
    'too long' => [str_repeat('a', 65)],
    'longer than the column' => [str_repeat('a', 300)],
    'not hex' => ['not-a-sha'],
    'trailing newline' => ["abcdef1\n"],
]);

test('a commit delivery whose identifier is not a sha stores no commit sha', function (): void {
    $project = Project::factory()->create();
    $ticket = Ticket::factory()->for($project)->create();
    $connection = sao_code_connection();
    sao_bind_code($connection, $project);

    $this->postJson(sao_code_url($connection), [
        'identifier' => 'release-branch-merge',
        'type' => 'commit',
        'text' => "Fixes {$ticket->key}",
    ], ['X-Code-Token' => 'shared', 'X-Delivery-Id' => 'del-1'])->assertStatus(202);

    expect(ChangeRef::query()->where('ticket_id', $ticket->id)->sole()->commit_sha)->toBeNull();
});
