<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\Environments\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Modules\Core\Filament\Utils\HasForm;

final class EnvironmentForm
{
    use HasForm;

    public static function configure(Schema $schema): Schema
    {
        return self::configureForm($schema
            ->components([
                Select::make('project_id')
                    ->relationship('project', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('name')
                    ->helperText(__('sao.help.environmentName'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('base_url')
                    ->url()
                    ->rules(['url:http,https'])
                    ->helperText(__('sao.help.environmentBaseUrl'))
                    ->maxLength(2048),
                TextInput::make('current_version')
                    ->helperText(__('sao.help.currentVersion'))
                    ->maxLength(255),
                TextInput::make('current_commit')
                    ->helperText(__('sao.help.currentCommit'))
                    ->maxLength(255),
                TextInput::make('current_branch')
                    ->helperText(__('sao.help.currentBranch'))
                    ->maxLength(255),
                DateTimePicker::make('last_seen_at')
                    ->helperText(__('sao.help.lastSeenAt')),
            ]));
    }
}
