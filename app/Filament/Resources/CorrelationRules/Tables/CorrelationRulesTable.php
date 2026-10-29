<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\CorrelationRules\Tables;

use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Modules\Core\Filament\Utils\HasTable;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\MatchType;

final class CorrelationRulesTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        return self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(
                    TextColumn::make('priority')
                        ->numeric()
                        ->sortable(),
                    TextColumn::make('target')
                        ->badge()
                        ->formatStateUsing(static fn (CorrelationTarget $state): string => __("sao.enums.correlationTarget.{$state->value}")),
                    TextColumn::make('match')
                        ->badge()
                        ->formatStateUsing(static fn (MatchType $state): string => __("sao.enums.matchType.{$state->value}")),
                    TextColumn::make('pattern')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('project.name')
                        ->searchable(),
                    TextColumn::make('environment')
                        ->toggleable(),
                    TextColumn::make('remoteConnection.name')
                        ->label(__('sao.fields.connection'))
                        ->placeholder('-')
                        ->toggleable(),
                    TextColumn::make('sourceProfile.name')
                        ->label(__('sao.fields.source_profile'))
                        ->placeholder('-')
                        ->toggleable(),
                );
            },
            filters: static function (Collection $default_filters): void {
                $default_filters->push(
                    SelectFilter::make('target')
                        ->options(array_combine(
                            CorrelationTarget::values(),
                            array_map(static fn (string $value): string => __("sao.enums.correlationTarget.{$value}"), CorrelationTarget::values()),
                        )),
                    SelectFilter::make('project')
                        ->relationship('project', 'name')
                        ->searchable()
                        ->preload(),
                );
            },
        )->defaultSort(static fn (Builder $query): Builder => $query->orderBy('priority')->orderBy('id'));
    }
}
