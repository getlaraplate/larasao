<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Setting;
use Modules\SAO\Database\Seeders\SAODatabaseSeeder;

uses(RefreshDatabase::class);

it('seeds the SAO runtime settings stamped with the SAO module', function (): void {
    $this->seed(SAODatabaseSeeder::class);

    $names = collect(SAODatabaseSeeder::runtimeSettingDefinitions())->pluck('name');

    $settings = Setting::query()->withoutGlobalScopes()->whereIn('name', $names)->get();

    expect($settings)->toHaveCount(9)
        ->and($settings->pluck('module')->unique()->all())->toBe(['SAO']);
});

it('is idempotent and leaves an operator-changed value untouched on a second run', function (): void {
    $this->seed(SAODatabaseSeeder::class);

    Setting::query()->withoutGlobalScopes()
        ->where('name', 'signals.window_minutes')
        ->update(['value' => json_encode(15), 'description' => 'drifted']);

    $this->seed(SAODatabaseSeeder::class);

    $setting = Setting::query()->withoutGlobalScopes()
        ->where('name', 'signals.window_minutes')->sole();

    expect($setting->value)->toBe(15)
        ->and($setting->description)->toBe('Length of the signal occurrence rolling window, in minutes')
        ->and(Setting::query()->withoutGlobalScopes()->where('module', 'SAO')->count())->toBe(9);
});
