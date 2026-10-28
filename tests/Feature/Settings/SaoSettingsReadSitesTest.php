<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Modules\Core\Models\Setting;
use Modules\Core\Services\DatabaseConfigOverlay;
use Modules\Core\Services\PerModelSettingResolver;
use Modules\Core\Services\SettingsCacheCoordinator;
use Modules\SAO\Database\Seeders\SAODatabaseSeeder;
use Modules\SAO\Enums\ClosureAction;
use Modules\SAO\Enums\ReleaseHealthVerdict;
use Modules\SAO\Ingest\SignalIngestService;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;
use Modules\SAO\Providers\SAOServiceProvider;
use Modules\SAO\Services\ClosureCoordinator;
use Modules\SAO\Services\ReleaseHealthService;

uses(RefreshDatabase::class);

/**
 * Stores an operator's value on a seeded SAO setting and applies the overlay the way a request does.
 */
function sao_store_setting(string $name, mixed $value): void
{
    Setting::query()->withoutGlobalScopes()
        ->where('module', 'SAO')->where('name', $name)
        ->update(['value' => json_encode($value)]);

    app(SettingsCacheCoordinator::class)->flushAll();
    app()->forgetScopedInstances();

    app(DatabaseConfigOverlay::class)->applyFromDatabase(app(PerModelSettingResolver::class));
}

/**
 * @return array<string, mixed>
 */
function sao_signal_payload(): array
{
    return [
        'kind' => 'exception',
        'class' => 'RuntimeException',
        'file' => 'Modules/AI/app/Loop.php',
        'function' => 'run',
        'message' => 'looping',
    ];
}

beforeEach(function (): void {
    $this->seed(SAODatabaseSeeder::class);
});

it('stops recording occurrences past the stored cap inside the stored window', function (): void {
    sao_store_setting('signals.max_occurrences_per_window', 2);
    sao_store_setting('signals.window_minutes', 5);

    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_signal_payload());
    $signal->occurrences()->update(['occurred_at' => now()->subMinutes(10)]);

    foreach (range(1, 3) as $ignored) {
        $signal = $service->ingest($project, sao_signal_payload());
    }

    // The occurrence older than the 5 minute window does not count toward the cap of 2.
    expect($signal->fresh()->occurrence_count)->toBe(3)
        ->and($signal->occurrences()->count())->toBe(3);
});

it('reads a stored zero occurrence cap as one', function (): void {
    sao_store_setting('signals.max_occurrences_per_window', 0);

    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $service->ingest($project, sao_signal_payload());
    $signal = $service->ingest($project, sao_signal_payload());

    expect($signal->occurrences()->count())->toBe(1);
});

it('reads a stored zero window as one minute', function (): void {
    sao_store_setting('signals.max_occurrences_per_window', 1);
    sao_store_setting('signals.window_minutes', 0);

    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_signal_payload());
    $signal->occurrences()->update(['occurred_at' => now()->subSeconds(30)]);

    $signal = $service->ingest($project, sao_signal_payload());

    expect($signal->occurrences()->count())->toBe(1);
});

it('reads a stored zero release health window as one day', function (): void {
    sao_store_setting('release_health.window_days', 0);

    $project = Project::factory()->create();
    $release = Release::factory()->for($project)->create(['released_at' => now()->subHours(6)]);
    $signal = Signal::factory()->for($project)->create(['group_key' => 'fresh', 'first_seen_at' => now()->subHours(3)]);
    SignalOccurrence::factory()->create(['signal_id' => $signal->id, 'environment' => 'production', 'occurred_at' => now()->subHours(3)]);

    $health = (new ReleaseHealthService)->forRelease($release);

    expect($health->verdict)->toBe(ReleaseHealthVerdict::Degraded)
        ->and($health->newSignals)->toBe(['fresh']);
});

it('reads a stored zero new signal threshold as one', function (): void {
    sao_store_setting('release_health.regressed_new_signals', 0);

    $project = Project::factory()->create();
    Release::factory()->for($project)->create(['released_at' => now()->subDays(10)]);
    $release = Release::factory()->for($project)->create(['released_at' => now()->subDays(2)]);
    $signal = Signal::factory()->for($project)->create(['group_key' => 'flat', 'first_seen_at' => now()->subDays(30)]);
    SignalOccurrence::factory()->count(2)->create(['signal_id' => $signal->id, 'environment' => 'production', 'occurred_at' => now()->subDay()]);
    SignalOccurrence::factory()->count(2)->create(['signal_id' => $signal->id, 'environment' => 'production', 'occurred_at' => now()->subDays(9)]);

    // No new signal at all: a threshold of zero would make this regressed.
    expect((new ReleaseHealthService)->forRelease($release)->verdict)->toBe(ReleaseHealthVerdict::Healthy);
});

it('reads a stored zero regression floor as one', function (): void {
    sao_store_setting('release_health.regression_min_occurrences', 0);

    $project = Project::factory()->create();
    Release::factory()->for($project)->create(['released_at' => now()->subDays(10)]);
    $release = Release::factory()->for($project)->create(['released_at' => now()->subDays(2)]);
    $signal = Signal::factory()->for($project)->create(['group_key' => 'flat', 'first_seen_at' => now()->subDays(30)]);
    SignalOccurrence::factory()->create(['signal_id' => $signal->id, 'environment' => 'production', 'occurred_at' => now()->subDay()]);
    SignalOccurrence::factory()->count(5)->create(['signal_id' => $signal->id, 'environment' => 'production', 'occurred_at' => now()->subDays(9)]);

    expect((new ReleaseHealthService)->forRelease($release)->regressedSignals)->toBe([]);
});

it('reads a stored regression factor below one as one', function (): void {
    sao_store_setting('release_health.regression_rate_factor', 0.2);

    $project = Project::factory()->create();
    Release::factory()->for($project)->create(['released_at' => now()->subDays(10)]);
    $release = Release::factory()->for($project)->create(['released_at' => now()->subDays(2)]);
    $signal = Signal::factory()->for($project)->create(['group_key' => 'easing', 'first_seen_at' => now()->subDays(30)]);
    SignalOccurrence::factory()->count(3)->create(['signal_id' => $signal->id, 'environment' => 'production', 'occurred_at' => now()->subDay()]);
    SignalOccurrence::factory()->count(4)->create(['signal_id' => $signal->id, 'environment' => 'production', 'occurred_at' => now()->subDays(9)]);

    // 3 against a baseline of 4 is a drop: a factor of 0.2 would flag it as a regression.
    expect((new ReleaseHealthService)->forRelease($release)->regressedSignals)->toBe([]);
});

it('closes a ticket only when the stored auto-close switch is on', function (bool $enabled, ClosureAction $expected): void {
    sao_store_setting('closure.auto_close.enabled', $enabled);
    ['ticket' => $ticket] = coord_closure_fixture();

    $audits = app(ClosureCoordinator::class)->forTicket($ticket->refresh(), 'production');

    expect($audits)->toHaveCount(1)
        ->and($audits[0]->action)->toBe($expected);
})->with([
    'on' => [true, ClosureAction::Close],
    'off' => [false, ClosureAction::Propose],
]);

it('registers the auto-open command on the scheduler only when the stored switch is on', function (bool $enabled): void {
    sao_store_setting('signals.auto_open.enabled', $enabled);

    // The schedule is built in a `booted` callback, before a console boot could have seen this row:
    // rebuild it the way a console process does after the overlay.
    $this->app->forgetInstance(Schedule::class);

    $registerSchedules = new ReflectionMethod(SAOServiceProvider::class, 'registerCommandSchedules');
    $registerSchedules->invoke(new SAOServiceProvider($this->app));

    $commands = collect(app(Schedule::class)->events())
        ->map(static fn (Event $event): string => (string) $event->command);

    expect($commands->contains(static fn (string $command): bool => str_contains($command, 'sao:signals:auto-open')))
        ->toBe($enabled);
})->with([
    'on' => [true],
    'off' => [false],
]);

it('lets the next queued job see a setting changed after the worker booted', function (): void {
    sao_store_setting('signals.window_minutes', 10);

    expect(config('sao.signals.window_minutes'))->toBe(10);

    // Another process saves the setting: the row and the persistent cache change, this worker's config does not.
    Setting::query()->withoutGlobalScopes()
        ->where('module', 'SAO')->where('name', 'signals.window_minutes')
        ->update(['value' => json_encode(5)]);
    app(SettingsCacheCoordinator::class)->flushAll();
    app()->forgetScopedInstances();

    expect(config('sao.signals.window_minutes'))->toBe(10);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn([]);

    event(new JobProcessing('database', $job));

    expect(config('sao.signals.window_minutes'))->toBe(5);
});
