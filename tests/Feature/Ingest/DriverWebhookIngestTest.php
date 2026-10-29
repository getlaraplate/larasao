<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Support\CrudApiExposure;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Enums\MatchType;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\CorrelationRule;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CrudApiExposure::enable();
});

/**
 * @param  list<Capability>  $capabilities
 */
function sao_logs_connection(string $driverKey, array $capabilities, string $secret = 'shared'): Connection
{
    return Connection::factory()->create([
        'driver_key' => $driverKey,
        'capabilities' => $capabilities,
        'credential' => ['secret' => $secret],
        'base_url' => null,
    ]);
}

function sao_bind_logs(Connection $connection, Project $project): ProjectBinding
{
    return ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Logs,
        'remote_identifier' => 'acme',
    ]);
}

/**
 * @return array<string, mixed>
 */
function sao_glitchtip_body(string $id = 'i-1', string $title = 'TypeError x'): array
{
    return ['data' => ['issue' => ['id' => $id, 'title' => $title, 'level' => 'error']]];
}

function sao_webhook_url(Connection $connection): string
{
    return '/api/v1/webhooks/' . $connection->getKey();
}

test('a signed logs delivery ingests a native-keyed signal into the bound project', function (): void {
    $project = Project::factory()->create();
    $connection = sao_logs_connection('glitchtip', [Capability::Logs]);
    sao_bind_logs($connection, $project);

    $response = $this->postJson(sao_webhook_url($connection), sao_glitchtip_body(), [
        'X-GlitchTip-Token' => 'shared',
        'X-Delivery-Id' => 'del-1',
    ]);

    $response->assertStatus(202)->assertJson(['result' => 'ingested']);

    $signal = Signal::query()->where('project_id', $project->getKey())->sole();

    expect($signal->group_key)->toBe('glitchtip:i-1')
        ->and($signal->occurrence_count)->toBe(1)
        ->and(IngestEvent::query()->where('status', IngestStatus::Ingested)->count())->toBe(1);
});

test('a delivery with a wrong token is rejected and nothing is stored', function (): void {
    $project = Project::factory()->create();
    $connection = sao_logs_connection('glitchtip', [Capability::Logs]);
    sao_bind_logs($connection, $project);

    $response = $this->postJson(sao_webhook_url($connection), sao_glitchtip_body(), [
        'X-GlitchTip-Token' => 'wrong',
    ]);

    $response->assertStatus(401);

    expect(Signal::query()->count())->toBe(0)
        ->and(IngestEvent::query()->count())->toBe(0);
});

test('a re-delivery with the same delivery id is deduped, not re-ingested', function (): void {
    $project = Project::factory()->create();
    $connection = sao_logs_connection('glitchtip', [Capability::Logs]);
    sao_bind_logs($connection, $project);

    $headers = ['X-GlitchTip-Token' => 'shared', 'X-Delivery-Id' => 'del-1'];

    $this->postJson(sao_webhook_url($connection), sao_glitchtip_body(), $headers)->assertStatus(202);
    $this->postJson(sao_webhook_url($connection), sao_glitchtip_body(), $headers)->assertStatus(202);

    $signal = Signal::query()->where('project_id', $project->getKey())->sole();

    expect($signal->occurrence_count)->toBe(1)
        ->and(SignalOccurrence::query()->count())->toBe(1)
        ->and(IngestEvent::query()->count())->toBe(1);
});

test('a delivery to a non-logs connection is unsupported', function (): void {
    $connection = sao_logs_connection('github', [Capability::Issues]);

    $response = $this->postJson(sao_webhook_url($connection), sao_glitchtip_body(), [
        'X-GlitchTip-Token' => 'shared',
    ]);

    $response->assertStatus(422)->assertJson(['result' => 'driver-has-no-logs-capability']);
});

test('an authentic delivery with no logs binding is accepted and audited but produces no signal', function (): void {
    $connection = sao_logs_connection('glitchtip', [Capability::Logs]);

    $response = $this->postJson(sao_webhook_url($connection), sao_glitchtip_body(), [
        'X-GlitchTip-Token' => 'shared',
        'X-Delivery-Id' => 'del-1',
    ]);

    $response->assertStatus(202)->assertJson(['result' => 'no-logs-binding']);

    expect(Signal::query()->count())->toBe(0)
        ->and(IngestEvent::query()->where('status', IngestStatus::Discarded)->where('outcome', 'no-logs-binding')->count())->toBe(1);
});

/**
 * @param  list<array<string, mixed>>  $backlog
 * @return array<string, mixed>
 */
function sao_graylog_body(array $backlog): array
{
    return ['event_definition_title' => 'High error rate', 'event' => ['source' => 'web-01'], 'backlog' => $backlog];
}

/**
 * @return array<string, mixed>
 */
function sao_graylog_message(string $message, string $url = 'https://shop.example.com/cart'): array
{
    return ['message' => $message, 'source' => 'web-01', 'fields' => ['application_url' => $url]];
}

/**
 * @return array<string, string>
 */
function sao_graylog_headers(string $deliveryId = 'd1'): array
{
    return ['X-Graylog-Token' => 'shared', 'X-Delivery-Id' => $deliveryId];
}

function sao_graylog_rule(Project $project, string $pattern = 'shop.example.com'): CorrelationRule
{
    return CorrelationRule::factory()->create([
        'project_id' => $project->getKey(),
        'target' => CorrelationTarget::RequestHost,
        'match' => MatchType::Exact,
        'pattern' => $pattern,
    ]);
}

test('each item of a delivery is correlated on its own by the operator rule', function (): void {
    $routed = Project::factory()->create();
    $other = Project::factory()->create();
    $connection = sao_logs_connection('graylog', [Capability::Logs]);
    sao_bind_logs($connection, $routed);
    sao_bind_logs($connection, $other);
    $rule = sao_graylog_rule($routed);

    $this->postJson(sao_webhook_url($connection), sao_graylog_body([
        sao_graylog_message('RuntimeException: one'),
        sao_graylog_message('RuntimeException: two', 'https://blog.example.com/'),
    ]), sao_graylog_headers())->assertStatus(202);

    $first = IngestEvent::query()->where('delivery_id', 'd1#0')->sole();
    $second = IngestEvent::query()->where('delivery_id', 'd1#1')->sole();

    expect($first->status)->toBe(IngestStatus::Ingested)
        ->and($first->project_id)->toBe($routed->getKey())
        ->and($first->winning_rule)->toBe("rule:{$rule->getKey()}")
        ->and(SignalOccurrence::query()->where('signal_id', $first->signal_id)->sole()->environment)->toBe('web-01')
        ->and($second->status)->toBe(IngestStatus::Uncorrelated)
        ->and($second->outcome)->toBe('no-correlation-rule-matched')
        ->and($second->signal_id)->toBeNull()
        ->and(Signal::query()->count())->toBe(1);
});

test('two logs bindings and no rule leave the event uncorrelated', function (): void {
    $connection = sao_logs_connection('graylog', [Capability::Logs]);
    sao_bind_logs($connection, Project::factory()->create());
    sao_bind_logs($connection, Project::factory()->create());

    $this->postJson(sao_webhook_url($connection), sao_graylog_body([sao_graylog_message('RuntimeException: one')]), sao_graylog_headers())
        ->assertStatus(202);

    $event = IngestEvent::query()->sole();

    expect($event->status)->toBe(IngestStatus::Uncorrelated)
        ->and($event->outcome)->toBe('no-correlation-rule-matched')
        ->and($event->project_id)->toBeNull()
        ->and(Signal::query()->count())->toBe(0);
});

test('a single logs binding and no rule ingests with the binding as winning rule', function (): void {
    $project = Project::factory()->create();
    $connection = sao_logs_connection('graylog', [Capability::Logs]);
    sao_bind_logs($connection, $project);

    $this->postJson(sao_webhook_url($connection), sao_graylog_body([sao_graylog_message('RuntimeException: one')]), sao_graylog_headers())
        ->assertStatus(202)
        ->assertJson(['result' => 'ingested']);

    $event = IngestEvent::query()->sole();

    expect($event->status)->toBe(IngestStatus::Ingested)
        ->and($event->winning_rule)->toBe('binding')
        ->and($event->project_id)->toBe($project->getKey());
});

test('with no logs binding every item is discarded with its canonical payload', function (): void {
    $connection = sao_logs_connection('graylog', [Capability::Logs]);

    $this->postJson(sao_webhook_url($connection), sao_graylog_body([
        sao_graylog_message('RuntimeException: one'),
        sao_graylog_message('RuntimeException: two'),
    ]), sao_graylog_headers())->assertStatus(202)->assertJson(['result' => 'no-logs-binding']);

    $events = IngestEvent::query()->orderBy('delivery_id')->get();

    expect($events)->toHaveCount(2)
        ->and($events->pluck('delivery_id')->all())->toBe(['d1#0', 'd1#1'])
        ->and($events->every(fn (IngestEvent $event): bool => $event->status === IngestStatus::Discarded && $event->outcome === 'no-logs-binding'))->toBeTrue()
        ->and($events[0]->payload['message'])->toBe('RuntimeException: one')
        ->and($events[0]->payload['server_host'])->toBe('web-01')
        ->and(Signal::query()->count())->toBe(0);
});

test('a delivery that unpacks to no item records nothing', function (): void {
    $connection = sao_logs_connection('graylog', [Capability::Logs]);
    sao_bind_logs($connection, Project::factory()->create());

    $this->postJson(sao_webhook_url($connection), ['event' => ['source' => 'web-01'], 'backlog' => []], sao_graylog_headers())
        ->assertStatus(202);

    expect(IngestEvent::query()->count())->toBe(0)
        ->and(Signal::query()->count())->toBe(0);
});

test('re-sending a delivery after a rule was added records and ingests nothing new', function (): void {
    $first = Project::factory()->create();
    $second = Project::factory()->create();
    $connection = sao_logs_connection('graylog', [Capability::Logs]);
    sao_bind_logs($connection, $first);
    sao_bind_logs($connection, $second);
    $body = sao_graylog_body([sao_graylog_message('RuntimeException: one')]);

    $this->postJson(sao_webhook_url($connection), $body, sao_graylog_headers())->assertStatus(202);

    sao_graylog_rule($second);

    $this->postJson(sao_webhook_url($connection), $body, sao_graylog_headers())->assertStatus(202);

    $event = IngestEvent::query()->sole();

    expect($event->status)->toBe(IngestStatus::Uncorrelated)
        ->and(Signal::query()->count())->toBe(0)
        ->and(SignalOccurrence::query()->count())->toBe(0);
});
