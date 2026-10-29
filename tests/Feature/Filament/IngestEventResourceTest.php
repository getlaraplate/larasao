<?php

declare(strict_types=1);

use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Tests\Support\HttpContext;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Filament\Resources\IngestEvents\IngestEventResource;
use Modules\SAO\Filament\Resources\IngestEvents\Pages\ListIngestEvents;
use Modules\SAO\Models\IngestEvent;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\SourceProfile;

uses(RefreshDatabase::class);

/**
 * A stored profile event a `project_key` correlation would now route: the project
 * exists, so reprocessing the row ingests it.
 */
function ingest_event_ui_event(IngestStatus $status, IngestPipeline $pipeline = IngestPipeline::Profile): IngestEvent
{
    return IngestEvent::factory()->create([
        'pipeline' => $pipeline,
        'payload' => [
            'source' => 'glitchtip',
            'host' => 'web-01',
            'project' => ['slug' => 'WIDGETS'],
            'error' => ['type' => 'RuntimeException', 'message' => 'boom id=1'],
        ],
        'status' => $status,
        'outcome' => 'kept',
    ]);
}

function ingest_event_ui_prepare(): void
{
    Project::factory()->create(['key_prefix' => 'WIDGETS']);
    SourceProfile::factory()->create([
        'name' => 'glitchtip',
        'matchers' => [['path' => 'source', 'operator' => 'equals', 'value' => 'glitchtip']],
        'field_bindings' => [
            'message' => 'error.message',
            'class' => 'error.type',
            'project_key' => 'project.slug',
            'server_host' => 'host',
        ],
    ]);
}

function sao_ingest_event_source(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 3) . '/app/Filament/Resources/IngestEvents/' . $relativePath);
}

test('the ingest event resource is bound to its model under the SAO group', function (): void {
    expect(IngestEventResource::getModel())->toBe(IngestEvent::class)
        ->and(IngestEventResource::getNavigationGroup())->toBe('sao.navigation.signals')
        ->and(IngestEventResource::getSlug())->toStartWith('sao/');
});

test('the ingest event resource is read-only: list and view, no create or edit', function (): void {
    expect(array_keys(IngestEventResource::getPages()))->toBe(['index', 'view'])
        ->and(IngestEventResource::canCreate())->toBeFalse();
});

test('the ingest event list offers no create action', function (): void {
    $contents = sao_ingest_event_source('Pages/ListIngestEvents.php');

    expect($contents)->toContain('getHeaderActions')
        ->and($contents)->toContain('return [];');
});

test('the table surfaces the delivery, status, outcome and correlation columns', function (): void {
    $table = sao_ingest_event_source('Tables/IngestEventsTable.php');

    expect($table)->toContain("TextColumn::make('delivery_id')")
        ->and($table)->toContain("TextColumn::make('status')")
        ->and($table)->toContain("TextColumn::make('outcome')")
        ->and($table)->toContain("TextColumn::make('signal.group_key')");
});

test('the infolist renders the raw payload alongside the outcome trail', function (): void {
    $infolist = sao_ingest_event_source('Schemas/IngestEventInfolist.php');

    expect($infolist)->toContain("TextEntry::make('payload')")
        ->and($infolist)->toContain('JSON_PRETTY_PRINT')
        ->and($infolist)->toContain("TextEntry::make('winning_rule')");
});

test('the reprocess row action shows only on an eligible row for a user who may reprocess', function (): void {
    $uncorrelated = ingest_event_ui_event(IngestStatus::Uncorrelated);
    $ingested = ingest_event_ui_event(IngestStatus::Ingested);
    $deploy = ingest_event_ui_event(IngestStatus::Discarded, IngestPipeline::Deploy);
    HttpContext::panelActorWithoutApproval(new IngestEvent, ['select', 'reprocess']);

    Livewire::test(ListIngestEvents::class)
        ->loadTable()
        ->assertTableActionVisible('reprocess', $uncorrelated)
        ->assertTableActionHidden('reprocess', $ingested)
        ->assertTableActionHidden('reprocess', $deploy);
});

test('the reprocess row action is hidden for a user without the reprocess permission', function (): void {
    $uncorrelated = ingest_event_ui_event(IngestStatus::Uncorrelated);
    HttpContext::panelActorWithoutApproval(new IngestEvent, ['select']);

    Livewire::test(ListIngestEvents::class)
        ->loadTable()
        ->assertTableActionHidden('reprocess', $uncorrelated);
});

test('the reprocess row action ingests the row and reports the outcome', function (): void {
    ingest_event_ui_prepare();
    $event = ingest_event_ui_event(IngestStatus::Uncorrelated);
    HttpContext::panelActorWithoutApproval(new IngestEvent, ['select', 'reprocess']);

    Livewire::test(ListIngestEvents::class)
        ->loadTable()
        ->callTableAction('reprocess', $event)
        ->assertNotified();

    $row = $event->fresh();

    expect($row->status)->toBe(IngestStatus::Ingested)
        ->and($row->reprocessed_at)->not->toBeNull()
        ->and(IngestEvent::query()->count())->toBe(1);
});

/**
 * Hiding is not enough: the test mounts and calls the action directly, the way a forged request would,
 * bypassing the visibility check of the test helpers.
 */
test('the reprocess row action does not run for a user without the permission', function (): void {
    ingest_event_ui_prepare();
    $event = ingest_event_ui_event(IngestStatus::Uncorrelated);
    HttpContext::panelActorWithoutApproval(new IngestEvent, ['select']);

    Livewire::test(ListIngestEvents::class)
        ->loadTable()
        ->call('mountAction', 'reprocess', [], ['table' => true, 'recordKey' => (string) $event->getKey()])
        ->call('callMountedAction');

    expect($event->fresh()->status)->toBe(IngestStatus::Uncorrelated)
        ->and($event->fresh()->reprocessed_at)->toBeNull();
});

test('the bulk reprocess acts on the selected rows only and counts the skipped ones', function (): void {
    ingest_event_ui_prepare();
    $selected = ingest_event_ui_event(IngestStatus::Uncorrelated);
    $skipped = ingest_event_ui_event(IngestStatus::Ingested);
    $unselected = ingest_event_ui_event(IngestStatus::Uncorrelated);
    HttpContext::panelActorWithoutApproval(new IngestEvent, ['select', 'reprocess']);

    Livewire::test(ListIngestEvents::class)
        ->loadTable()
        ->callTableBulkAction('reprocess', [$selected, $skipped])
        ->assertNotified(
            Notification::make()
                ->title(__('sao.messages.reprocessDone'))
                ->body(__('sao.messages.reprocessCounts', ['ingested' => 1, 'unmatched' => 0, 'failed' => 0, 'skipped' => 1]))
                ->success(),
        );

    expect($selected->fresh()->status)->toBe(IngestStatus::Ingested)
        ->and($skipped->fresh()->reprocessed_at)->toBeNull()
        ->and($unselected->fresh()->status)->toBe(IngestStatus::Uncorrelated);
});

test('the bulk reprocess is hidden and refused for a user without the permission', function (): void {
    ingest_event_ui_prepare();
    $event = ingest_event_ui_event(IngestStatus::Uncorrelated);
    HttpContext::panelActorWithoutApproval(new IngestEvent, ['select']);

    Livewire::test(ListIngestEvents::class)
        ->loadTable()
        ->assertTableBulkActionHidden('reprocess')
        ->set('selectedTableRecords', [(string) $event->getKey()])
        ->call('mountAction', 'reprocess', [], ['table' => true, 'bulk' => true])
        ->call('callMountedAction');

    expect($event->fresh()->status)->toBe(IngestStatus::Uncorrelated)
        ->and($event->fresh()->reprocessed_at)->toBeNull();
});

test('the reprocess counts are worded in each locale without the English status name', function (string $locale): void {
    $message = __('sao.messages.reprocessCounts', ['ingested' => 1, 'unmatched' => 0, 'failed' => 0, 'skipped' => 0], $locale);

    expect($message)->not->toContain('Ingested')
        ->and($message)->not->toContain('sao.messages');
})->with(['de', 'es', 'it', 'sl']);
