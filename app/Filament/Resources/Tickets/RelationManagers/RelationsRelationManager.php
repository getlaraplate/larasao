<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\Tickets\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\SAO\Enums\TicketRelationType;
use Override;

/**
 * The outgoing typed relations of a ticket (blocks / duplicates / relates). The
 * inverse reading ("blocked by") is derived by query on the target ticket, so
 * only the outgoing side is edited here.
 */
final class RelationsRelationManager extends RelationManager
{
    protected static string $relationship = 'relations';

    #[Override]
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return (string) __('sao.pages.relations');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('type')
                    ->options(TicketRelationType::class)
                    ->required(),
                Select::make('target_ticket_id')
                    ->relationship('target', 'key')
                    ->searchable()
                    ->preload()
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('type')
                    ->badge(),
                TextColumn::make('target.key')
                    ->searchable(),
                TextColumn::make('target.title')
                    ->limit(40)
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                DeleteAction::make(),
            ]);
    }
}
