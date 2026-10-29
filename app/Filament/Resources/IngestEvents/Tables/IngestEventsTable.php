<?php

declare(strict_types=1);

namespace Modules\SAO\Filament\Resources\IngestEvents\Tables;

use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Filament\Utils\HasTable;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Ingest\IngestReprocessService;
use Modules\SAO\Ingest\ReprocessOutcome;
use Modules\SAO\Models\IngestEvent;

/**
 * The list of stored deliveries. Besides the read-only columns it offers the
 * backoffice fix-up for an event that did not become a signal: reprocess one
 * row, or the selected rows, through {@see IngestReprocessService} once the
 * operator has fixed what stopped it. Both actions are gated by the `reprocess`
 * permission on the ingest events, enforced when they run and not only when
 * they are shown.
 */
final class IngestEventsTable
{
    use HasTable;

    public static function configure(Table $table): Table
    {
        $table = self::configureTable(
            table: $table,
            columns: static function (Collection $default_columns): void {
                $default_columns->unshift(
                    TextColumn::make('delivery_id')
                        ->limit(24)
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('status')
                        ->badge()
                        ->sortable(),
                    TextColumn::make('outcome')
                        ->placeholder('—')
                        ->searchable(),
                    TextColumn::make('connection.name')
                        ->placeholder('—')
                        ->sortable(),
                    TextColumn::make('project.name')
                        ->placeholder('—')
                        ->sortable(),
                    TextColumn::make('signal.group_key')
                        ->placeholder('—')
                        ->limit(32),
                    TextColumn::make('created_at')
                        ->dateTime()
                        ->sortable(),
                );
            },
            actions: static function (Collection $default_actions): void {
                $default_actions->unshift(
                    Action::make('reprocess')
                        ->label(__('sao.actions.reprocess'))
                        ->icon(Heroicon::OutlinedArrowPath)
                        ->authorize('reprocess')
                        ->visible(static fn (IngestEvent $record): bool => IngestReprocessService::isEligible($record))
                        ->requiresConfirmation()
                        ->action(static function (IngestEvent $record): void {
                            self::notifyOutcomes(collect([app(IngestReprocessService::class)->reprocess($record)]));
                        }),
                );
            },
        );

        return $table->pushToolbarActions([
            BulkActionGroup::make([
                BulkAction::make('reprocess')
                    ->label(__('sao.actions.reprocess'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize(static fn (): bool => Gate::allows('reprocess', new IngestEvent))
                    ->requiresConfirmation()
                    ->deselectRecordsAfterCompletion()
                    ->action(static function (Collection $records): void {
                        self::notifyOutcomes(app(IngestReprocessService::class)->reprocessMany(
                            IngestEvent::query()->whereKey($records->modelKeys()),
                        ));
                    }),
            ]),
        ]);
    }

    /**
     * Reports how a reprocess went: how many events ended `ingested`, how many
     * are still unmatched, how many failed, and how many were left alone
     * because they were not eligible (or not written).
     *
     * @param  Collection<int, ReprocessOutcome>  $outcomes
     */
    private static function notifyOutcomes(Collection $outcomes): void
    {
        $applied = $outcomes->filter(static fn (ReprocessOutcome $outcome): bool => $outcome->applied);

        $failed = $applied->filter(static fn (ReprocessOutcome $outcome): bool => $outcome->after === IngestStatus::Failed)->count();
        $ingested = $applied->filter(static fn (ReprocessOutcome $outcome): bool => $outcome->after === IngestStatus::Ingested)->count();

        $notification = Notification::make()
            ->title(__('sao.messages.reprocessDone'))
            ->body(__('sao.messages.reprocessCounts', [
                'ingested' => $ingested,
                'unmatched' => $applied->count() - $ingested - $failed,
                'failed' => $failed,
                'skipped' => $outcomes->count() - $applied->count(),
            ]));

        $failed > 0 ? $notification->warning() : $notification->success();
        $notification->send();
    }
}
