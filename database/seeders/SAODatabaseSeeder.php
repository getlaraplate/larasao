<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Seeders;

use Modules\Core\Casts\SettingTypeEnum;
use Modules\Core\Overrides\Seeder;
use Modules\Core\Seeding\SeedReconciler;

class SAODatabaseSeeder extends Seeder
{
    /**
     * Every row carries the same column set (`encrypted`, `choices` included even when null):
     * {@see SeedReconciler} batches these rows into a single `upsert()`, which requires a uniform
     * column set across the whole batch.
     *
     * @return array<int, array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}>
     */
    public static function runtimeSettingDefinitions(): array
    {
        return [
            self::setting('signals.max_occurrences_per_window', 1000, SettingTypeEnum::Integer, 'Occurrences of one signal recorded within the rolling window before further ones are dropped'),
            self::setting('signals.window_minutes', 60, SettingTypeEnum::Integer, 'Length of the signal occurrence rolling window, in minutes'),
            self::setting('signals.auto_open.enabled', false, SettingTypeEnum::Boolean, 'Schedule the automatic opening of tickets from error signals'),
            self::setting('signals.auto_open.min_occurrences', 1, SettingTypeEnum::Integer, 'Occurrences a signal needs before it opens a ticket automatically'),
            self::setting('release_health.window_days', 7, SettingTypeEnum::Integer, 'Days after a release whose signals are attributed to it when judging release health'),
            self::setting('release_health.regression_rate_factor', 1.5, SettingTypeEnum::Float, 'Factor by which an existing signal must exceed its baseline count to count as regressed'),
            self::setting('release_health.regression_min_occurrences', 3, SettingTypeEnum::Integer, 'Occurrences in the window an existing signal needs before it can count as regressed'),
            self::setting('release_health.regressed_new_signals', 3, SettingTypeEnum::Integer, 'New signals in the window that on their own turn a release verdict to regressed'),
            self::setting('closure.auto_close.enabled', false, SettingTypeEnum::Boolean, 'Let a satisfied close policy close the ticket instead of only proposing it'),
        ];
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $outcome = app(SeedReconciler::class)->reconcile(
            self::internalSettingsDefinition('SAO', self::runtimeSettingDefinitions()),
        );

        $this->command?->line(
            '    - created ' . count($outcome->created) . ', realigned ' . count($outcome->realigned) . ", unchanged {$outcome->unchanged}",
        );

        $this->call(SAOPermissionSeeder::class);
    }

    /**
     * @return array{name: string, value: mixed, encrypted: bool, choices: ?array<int, mixed>, type: SettingTypeEnum, group_name: string, description: string}
     */
    private static function setting(string $name, mixed $value, SettingTypeEnum $type, string $description): array
    {
        return [
            'name' => $name,
            'value' => $value,
            'encrypted' => false,
            'choices' => null,
            'type' => $type,
            'group_name' => 'sao',
            'description' => $description,
        ];
    }
}
