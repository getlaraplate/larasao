<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\TicketReleaseState;
use Modules\SAO\Ingest\SignalIngestService;
use Modules\SAO\Jobs\CheckFixPresenceJob;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketRelease;
use Modules\SAO\Services\FixStatusResolver;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(SignalIngestService::class);
    $this->project = Project::factory()->create();
});

test('ingest records the reported version on the occurrence and censuses it', function (): void {
    $signal = $this->service->ingest($this->project, [
        'message' => 'Boom',
        'version' => 'v1.4.0',
    ]);

    $occurrence = $signal->occurrences()->sole();
    $release = Release::query()->sole();

    expect($occurrence->affected_version)->toBe('1.4.0')
        ->and($occurrence->affected_release_id)->toBe($release->getKey())
        ->and($release->status)->toBe(ReleaseStatus::Observed)
        ->and($release->version)->toBe('1.4.0');
});

test('ingest keeps an unnormalizable version raw without censusing it', function (): void {
    $signal = $this->service->ingest($this->project, [
        'message' => 'Boom',
        'version' => 'nightly',
    ]);

    expect($signal->occurrences()->sole()->affected_version)->toBe('nightly')
        ->and($signal->occurrences()->sole()->affected_release_id)->toBeNull()
        ->and(Release::query()->count())->toBe(0);
});

test('ingest without a reported version leaves the occurrence version empty', function (): void {
    $signal = $this->service->ingest($this->project, ['message' => 'Boom']);

    expect($signal->occurrences()->sole()->affected_version)->toBeNull()
        ->and($signal->occurrences()->sole()->affected_release_id)->toBeNull();
});

test('a live occurrence updates the census of the existing environment of that name', function (): void {
    $environment = Environment::factory()->for($this->project)->create(['name' => 'customer-a']);

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'customer-a',
        'version' => '1.4.0',
        'commit' => 'abc1234',
        'branch' => 'main',
    ]);

    $environment->refresh();

    expect($environment->current_version)->toBe('1.4.0')
        ->and($environment->current_commit)->toBe('abc1234')
        ->and($environment->current_branch)->toBe('main')
        ->and($environment->last_seen_at)->not->toBeNull();
});

test('an occurrence naming an unknown environment creates and stamps nothing', function (): void {
    $other = Environment::factory()->for($this->project)->create(['name' => 'customer-a']);

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'web-01',
        'version' => '1.4.0',
        'commit' => 'abc1234',
    ]);

    expect(Environment::query()->count())->toBe(1)
        ->and($other->refresh()->current_version)->toBeNull()
        ->and($other->last_seen_at)->toBeNull();
});

test('an environment of another project with the same name is not stamped', function (): void {
    $foreign = Environment::factory()->create(['name' => 'customer-a']);

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'customer-a',
        'version' => '1.4.0',
    ]);

    expect($foreign->refresh()->current_version)->toBeNull();
});

test('an occurrence without a version leaves the environment untouched', function (): void {
    $environment = Environment::factory()->for($this->project)->create([
        'name' => 'customer-a',
        'current_version' => '1.0.0',
    ]);

    $this->service->ingest($this->project, ['message' => 'Boom', 'environment' => 'customer-a']);

    expect($environment->refresh()->current_version)->toBe('1.0.0')
        ->and($environment->last_seen_at)->toBeNull();
});

test('a replayed occurrence does not overwrite the environment census', function (): void {
    $environment = Environment::factory()->for($this->project)->create([
        'name' => 'customer-a',
        'current_version' => '2.0.0',
        'current_commit' => 'new0000',
    ]);

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'customer-a',
        'version' => '1.0.0',
        'commit' => 'old1111',
    ], now()->subDays(30));

    $environment->refresh();

    expect($environment->current_version)->toBe('2.0.0')
        ->and($environment->current_commit)->toBe('new0000');
});

test('a rate-limited occurrence does not observe the census', function (): void {
    config()->set('sao.signals.max_occurrences_per_window', 1);
    $environment = Environment::factory()->for($this->project)->create(['name' => 'customer-a']);
    $payload = ['message' => 'Boom', 'environment' => 'customer-a'];

    $this->service->ingest($this->project, $payload);
    $this->service->ingest($this->project, [...$payload, 'version' => '1.4.0']);

    expect($environment->refresh()->current_version)->toBeNull();
});

test('an occurrence version is stored as the release it resolves to, so the fix status does not move', function (): void {
    $release = Release::factory()->for($this->project)->shipped()->create(['version' => '1.4.0']);
    $ticket = Ticket::factory()->for($this->project)->create();
    TicketRelease::factory()->create([
        'ticket_id' => $ticket->id,
        'release_id' => $release->id,
        'state' => TicketReleaseState::Shipped,
    ]);
    $environment = Environment::factory()->for($this->project)->create(['name' => 'production', 'current_version' => '1.4.0']);

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'production',
        'version' => 'v1.4.0',
    ]);

    $status = (new FixStatusResolver())->forTicket($ticket->refresh());

    expect($environment->refresh()->current_version)->toBe('1.4.0')
        ->and($environment->last_seen_at)->not->toBeNull()
        ->and($status->deployed_environments)->toBe(['production'])
        ->and($status->missing_environments)->toBe([]);
});

test('an occurrence version with build noise is stored as its release version', function (): void {
    $environment = Environment::factory()->for($this->project)->create(['name' => 'production']);

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'production',
        'version' => '1.4.0 (build 123)',
    ]);

    expect($environment->refresh()->current_version)->toBe('1.4.0');
});

test('an occurrence version that resolves to no release leaves the environment untouched', function (): void {
    $environment = Environment::factory()->for($this->project)->create([
        'name' => 'production',
        'current_version' => '1.4.0',
        'current_commit' => 'abc1234',
    ]);

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'production',
        'version' => 'nightly',
        'commit' => 'def5678',
    ]);

    $environment->refresh();

    expect($environment->current_version)->toBe('1.4.0')
        ->and($environment->current_commit)->toBe('abc1234')
        ->and($environment->last_seen_at)->toBeNull();
});

test('an occurrence commit longer than any sha is ignored and the ingest still succeeds', function (): void {
    $environment = Environment::factory()->for($this->project)->create([
        'name' => 'production',
        'current_commit' => 'abc1234',
        'current_branch' => 'main',
    ]);

    $signal = $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'production',
        'version' => '1.4.0',
        'commit' => str_repeat('a', 300),
        'branch' => str_repeat('b', 300),
    ]);

    $environment->refresh();

    expect($signal->occurrences()->count())->toBe(1)
        ->and($environment->current_version)->toBe('1.4.0')
        ->and($environment->current_commit)->toBe('abc1234')
        ->and($environment->current_branch)->toBe('main');
});

test('an occurrence commit that is not a sha changes nothing and queues no presence check', function (): void {
    $environment = Environment::factory()->for($this->project)->create([
        'name' => 'production',
        'current_commit' => 'abc1234',
    ]);
    Queue::fake();

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'production',
        'version' => '1.4.0',
        'commit' => 'release-candidate',
    ]);

    expect($environment->refresh()->current_commit)->toBe('abc1234');
    Queue::assertNotPushed(CheckFixPresenceJob::class);
});

test('an occurrence sha is stored lowercase', function (): void {
    $environment = Environment::factory()->for($this->project)->create(['name' => 'production']);

    $this->service->ingest($this->project, [
        'message' => 'Boom',
        'environment' => 'production',
        'version' => '1.4.0',
        'commit' => 'ABC1234',
    ]);

    expect($environment->refresh()->current_commit)->toBe('abc1234');
});

test('a second unchanged occurrence within a minute does not write the environment again', function (): void {
    $environment = Environment::factory()->for($this->project)->create(['name' => 'production']);
    $payload = ['message' => 'Boom', 'environment' => 'production', 'version' => '1.4.0', 'commit' => 'abc1234', 'branch' => 'main'];

    $this->service->ingest($this->project, $payload);
    $firstSeen = $environment->refresh()->last_seen_at;

    $this->travel(30)->seconds();

    $updates = 0;
    DB::listen(static function (QueryExecuted $query) use (&$updates): void {
        if (str_starts_with(mb_strtolower($query->sql), 'update') && str_contains($query->sql, SAOTables::Environments->value)) {
            $updates++;
        }
    });

    $this->service->ingest($this->project, $payload);

    expect($updates)->toBe(0)
        ->and($environment->refresh()->last_seen_at->equalTo($firstSeen))->toBeTrue();
});

test('an unchanged occurrence after a minute stamps the environment again', function (): void {
    $environment = Environment::factory()->for($this->project)->create(['name' => 'production']);
    $payload = ['message' => 'Boom', 'environment' => 'production', 'version' => '1.4.0'];

    $this->service->ingest($this->project, $payload);
    $firstSeen = $environment->refresh()->last_seen_at;

    $this->travel(61)->seconds();
    $this->service->ingest($this->project, $payload);

    expect($environment->refresh()->last_seen_at->greaterThan($firstSeen))->toBeTrue();
});

test('a changed occurrence within a minute writes the environment', function (): void {
    $environment = Environment::factory()->for($this->project)->create(['name' => 'production']);

    $this->service->ingest($this->project, ['message' => 'Boom', 'environment' => 'production', 'version' => '1.4.0']);
    $this->travel(10)->seconds();
    $this->service->ingest($this->project, ['message' => 'Boom', 'environment' => 'production', 'version' => '1.5.0']);

    expect($environment->refresh()->current_version)->toBe('1.5.0');
});
