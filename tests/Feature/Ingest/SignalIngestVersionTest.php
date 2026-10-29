<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Ingest\SignalIngestService;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;

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
