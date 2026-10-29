<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\CorrelationRules\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\MatchType;

final class CorrelationRuleForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        return self::configureForm($schema
            ->components([
                Select::make('target')
                    ->options(self::enumOptions(CorrelationTarget::cases(), 'correlationTarget'))
                    ->helperText(__('sao.help.correlationTarget'))
                    ->required(),
                Select::make('match')
                    ->options(self::enumOptions(MatchType::cases(), 'matchType'))
                    ->default(MatchType::Exact->value)
                    ->helperText(__('sao.help.matchType'))
                    ->required(),
                TextInput::make('pattern')
                    ->helperText(__('sao.help.correlationPattern'))
                    ->required()
                    ->maxLength(255),
                Select::make('project_id')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('environment')
                    ->helperText(__('sao.help.ruleEnvironment'))
                    ->maxLength(255),
                Select::make('connection_id')
                    ->relationship('remoteConnection', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText(__('sao.help.ruleConnectionScope')),
                Select::make('source_profile_id')
                    ->relationship('sourceProfile', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText(__('sao.help.ruleProfileScope')),
                TextInput::make('priority')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(100)
                    ->helperText(__('sao.help.rulePriority'))
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
            ]));
    }

    /**
     * @param  list<CorrelationTarget|MatchType>  $cases
     * @return array<string, string>
     */
    private static function enumOptions(array $cases, string $group): array
    {
        $options = [];

        foreach ($cases as $case) {
            $options[$case->value] = __("sao.enums.{$group}.{$case->value}");
        }

        return $options;
    }
}
