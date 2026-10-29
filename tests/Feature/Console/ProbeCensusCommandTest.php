<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;
use Modules\SAO\Providers\SAOServiceProvider;

uses(RefreshDatabase::class);

/**
 * A project with a `probe` binding over the real http-version driver.
 */
function probe_project(string $keyPrefix = 'SHP'): Project
{
    $project = Project::factory()->create(['key_prefix' => $keyPrefix]);

    $connection = Connection::factory()->create([
        'driver_key' => 'http-version',
        'base_url' => null,
        'capabilities' => [Capability::Probe],
        'config' => ['version_path' => 'version', 'commit_path' => 'commit', 'branch_path' => 'branch'],
    ]);

    ProjectBinding::factory()->create([
        'project_id' => $project->getKey(),
        'connection_id' => $connection->getKey(),
        'capability' => Capability::Probe,
        'remote_identifier' => 'version',
    ]);

    return $project;
}

function probe_environment(Project $project, string $name, ?string $baseUrl): Environment
{
    return Environment::factory()->create([
        'project_id' => $project->getKey(),
        'name' => $name,
        'base_url' => $baseUrl,
    ]);
}

test('it records the probed version, commit and branch and succeeds', function (): void {
    Http::fake(['*' => Http::response(['version' => '2.1.0', 'commit' => 'abc123', 'branch' => 'main'])]);
    $environment = probe_environment(probe_project(), 'production', 'https://shop.example.com');

    $this->artisan('sao:census:probe')->assertSuccessful();

    $environment->refresh();
    expect($environment->current_version)->toBe('2.1.0')
        ->and($environment->current_commit)->toBe('abc123')
        ->and($environment->current_branch)->toBe('main')
        ->and($environment->last_seen_at)->not->toBeNull();
});

test('one failing environment is reported and left unchanged while the other is recorded', function (): void {
    Http::fake([
        'https://good.example.com/*' => Http::response(['version' => '3.0.0']),
        'https://bad.example.com/*' => Http::response([], 500),
    ]);
    $project = probe_project();
    $good = probe_environment($project, 'production', 'https://good.example.com');
    $bad = probe_environment($project, 'staging', 'https://bad.example.com');
    $bad->forceFill(['current_version' => '1.0.0'])->save();

    $this->artisan('sao:census:probe')
        ->expectsOutputToContain('SHP staging: failed')
        ->expectsOutputToContain('SHP production: 3.0.0')
        ->assertExitCode(1);

    expect($good->fresh()->current_version)->toBe('3.0.0')
        ->and($bad->fresh()->current_version)->toBe('1.0.0')
        ->and($bad->fresh()->last_seen_at)->toBeNull();
});

test('an unexpected error on one environment does not abort the run', function (): void {
    Log::spy();
    Http::fake([
        'https://boom.example.com/*' => static fn () => throw new RuntimeException('secret detail'),
        'https://good.example.com/*' => Http::response(['version' => '3.0.0']),
    ]);
    $project = probe_project();
    $boom = probe_environment($project, 'production', 'https://boom.example.com');
    $good = probe_environment($project, 'staging', 'https://good.example.com');

    $this->artisan('sao:census:probe')
        ->expectsOutputToContain('SHP production: failed — RuntimeException')
        ->assertExitCode(1);

    expect($boom->fresh()->current_version)->toBeNull()
        ->and($good->fresh()->current_version)->toBe('3.0.0');

    Log::shouldHaveReceived('warning')->once();
});

test('a stored auth header is sent with the probe', function (): void {
    Http::fake(['*' => Http::response(['version' => '2.0.0'])]);
    $project = probe_project();
    $project->bindings()->first()->remoteConnection->update(['credential' => ['auth_header' => 'X-Probe: s3cret']]);
    probe_environment($project, 'production', 'https://shop.example.com');

    $this->artisan('sao:census:probe')->assertSuccessful();

    Http::assertSent(static fn (Request $request): bool => $request->hasHeader('X-Probe', 's3cret'));
});

test('a credential reference that does not resolve is a failure', function (): void {
    Http::fake(['*' => Http::response(['version' => '2.0.0'])]);
    $project = probe_project();
    $project->bindings()->first()->remoteConnection->update(['credential_ref' => 'services.sao_probe_missing']);
    $environment = probe_environment($project, 'production', 'https://shop.example.com');

    $this->artisan('sao:census:probe')->expectsOutputToContain('failed')->assertExitCode(1);

    Http::assertNothingSent();
    expect($environment->fresh()->current_version)->toBeNull();
});

test('an unreachable environment is a failure', function (): void {
    Http::fake(['*' => static fn () => throw new ConnectionException('down')]);
    probe_environment(probe_project(), 'production', 'https://shop.example.com');

    $this->artisan('sao:census:probe')->expectsOutputToContain('failed')->assertExitCode(1);
});

test('environments without a base url are skipped', function (): void {
    Http::fake(['*' => Http::response(['version' => '2.0.0'])]);
    $project = probe_project();
    $without = probe_environment($project, 'staging', null);

    $this->artisan('sao:census:probe')->assertSuccessful();

    Http::assertNothingSent();
    expect($without->fresh()->current_version)->toBeNull();
});

test('projects without a probe binding are skipped', function (): void {
    Http::fake(['*' => Http::response(['version' => '2.0.0'])]);
    $project = Project::factory()->create(['key_prefix' => 'NOP']);
    $environment = probe_environment($project, 'production', 'https://shop.example.com');

    $this->artisan('sao:census:probe')->assertSuccessful();

    Http::assertNothingSent();
    expect($environment->fresh()->current_version)->toBeNull();
});

test('the project argument limits the run to the matching key prefix', function (): void {
    Http::fake(['*' => Http::response(['version' => '2.0.0'])]);
    $target = probe_environment(probe_project('AAA'), 'production', 'https://a.example.com');
    $other = probe_environment(probe_project('BBB'), 'production', 'https://b.example.com');

    $this->artisan('sao:census:probe', ['project' => 'AAA'])->assertSuccessful();

    expect($target->fresh()->current_version)->toBe('2.0.0')
        ->and($other->fresh()->current_version)->toBeNull();
});

test('an unknown project key prefix fails with a message', function (): void {
    $this->artisan('sao:census:probe', ['project' => 'NOPE'])
        ->expectsOutputToContain('Project [NOPE] not found.')
        ->assertExitCode(1);
});

test('a probed value longer than its column is a failure, not a database error', function (): void {
    Http::fake(['*' => Http::response(['version' => str_repeat('9', 300), 'commit' => 'abc'])]);
    $environment = probe_environment(probe_project(), 'production', 'https://shop.example.com');

    $this->artisan('sao:census:probe')->expectsOutputToContain('failed')->assertExitCode(1);

    expect($environment->fresh()->current_version)->toBeNull()
        ->and($environment->fresh()->current_commit)->toBeNull();
});

test('a commit or branch longer than its column is a failure', function (string $field): void {
    Http::fake(['*' => Http::response(['version' => '1.0.0', $field => str_repeat('x', 256)])]);
    $environment = probe_environment(probe_project(), 'production', 'https://shop.example.com');

    $this->artisan('sao:census:probe')->expectsOutputToContain('failed')->assertExitCode(1);

    expect($environment->fresh()->current_version)->toBeNull();
})->with(['commit', 'branch']);

test('the probe is scheduled only when enabled and uses the configured cron', function (bool $enabled): void {
    config(['sao.probe.enabled' => $enabled, 'sao.probe.cron' => '5 4 * * *']);

    $this->app->forgetInstance(Schedule::class);

    $registerSchedules = new ReflectionMethod(SAOServiceProvider::class, 'registerCommandSchedules');
    $registerSchedules->invoke(new SAOServiceProvider($this->app));

    $events = collect(app(Schedule::class)->events())
        ->filter(static fn (Event $event): bool => str_contains((string) $event->command, 'sao:census:probe'));

    expect($events)->toHaveCount($enabled ? 1 : 0);

    if ($enabled) {
        expect($events->first()->expression)->toBe('5 4 * * *');
    }
})->with([
    'on' => [true],
    'off' => [false],
]);

test('the probe schedule is off and the cron defaults to every six hours', function (): void {
    expect(config('sao.probe.enabled'))->toBeFalse()
        ->and(config('sao.probe.cron'))->toBe('0 */6 * * *');
});

test('a probe binding of a deleted project is skipped and the others are probed', function (): void {
    Http::fake(['*' => Http::response(['version' => '2.1.0'])]);
    $deleted = probe_project('OLD');
    probe_environment($deleted, 'production', 'https://old.example.com');
    $environment = probe_environment(probe_project('SHP'), 'production', 'https://shop.example.com');
    $deleted->delete();

    $this->artisan('sao:census:probe')
        ->expectsOutputToContain('SHP production: 2.1.0')
        ->doesntExpectOutputToContain('OLD')
        ->assertSuccessful();

    expect($environment->refresh()->current_version)->toBe('2.1.0');
});
