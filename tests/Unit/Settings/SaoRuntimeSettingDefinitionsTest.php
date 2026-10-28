<?php

declare(strict_types=1);

use Modules\Core\Casts\SettingTypeEnum;
use Modules\SAO\Database\Seeders\SAODatabaseSeeder;
use Modules\SAO\Tests\TestCase;

uses(TestCase::class);

it('defines the tunables that were env variables, with the defaults they had', function (string $name, mixed $value, SettingTypeEnum $type): void {
    $definition = collect(SAODatabaseSeeder::runtimeSettingDefinitions())->keyBy('name')->get($name);

    expect($definition)->not->toBeNull()
        ->and($definition['value'])->toBe($value)
        ->and($definition['type'])->toBe($type)
        ->and($definition['choices'])->toBeNull()
        ->and($definition['encrypted'])->toBeFalse()
        ->and($definition['group_name'])->toBe('sao')
        ->and($definition['description'])->toBeString()->not->toBeEmpty()
        ->and(config('sao.' . $name))->toBe($value);
})->with([
    'signal occurrence cap' => ['signals.max_occurrences_per_window', 1000, SettingTypeEnum::Integer],
    'signal window' => ['signals.window_minutes', 60, SettingTypeEnum::Integer],
    'auto-open switch' => ['signals.auto_open.enabled', false, SettingTypeEnum::Boolean],
    'auto-open threshold' => ['signals.auto_open.min_occurrences', 1, SettingTypeEnum::Integer],
    'release health window' => ['release_health.window_days', 7, SettingTypeEnum::Integer],
    'release health regression factor' => ['release_health.regression_rate_factor', 1.5, SettingTypeEnum::Float],
    'release health regression floor' => ['release_health.regression_min_occurrences', 3, SettingTypeEnum::Integer],
    'release health new signals' => ['release_health.regressed_new_signals', 3, SettingTypeEnum::Integer],
    'auto-close switch' => ['closure.auto_close.enabled', false, SettingTypeEnum::Boolean],
]);

it('defines exactly nine settings', function (): void {
    expect(SAODatabaseSeeder::runtimeSettingDefinitions())->toHaveCount(9);
});
