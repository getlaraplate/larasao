<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\SignalState;
use Modules\SAO\Ingest\GroupKeyResolver;
use Modules\SAO\Ingest\SignalIngestService;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Signal;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function sao_error_payload(array $overrides = []): array
{
    return array_merge([
        'kind' => 'exception',
        'class' => 'RuntimeException',
        'file' => 'Modules/AI/app/Jobs/GenerateEmbeddingsJob.php',
        'function' => 'handle',
        'message' => 'Model failed id=1',
        'environment' => 'production',
    ], $overrides);
}

test('a native key wins and is namespaced by source', function (): void {
    $project = Project::factory()->create();

    $signal = app(SignalIngestService::class)->ingest($project, [
        'native_key' => 'issue-42',
        'source' => 'sentry',
        'message' => 'anything',
    ]);

    expect($signal->group_key)->toBe('sentry:issue-42');
});

test('two matching payloads recur one signal and count two occurrences', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $first = $service->ingest($project, sao_error_payload());
    $second = $service->ingest($project, sao_error_payload(['message' => 'Model failed id=999']));

    expect($second->id)->toBe($first->id)
        ->and($first->fresh()->occurrence_count)->toBe(2)
        ->and($first->occurrences()->count())->toBe(2);
});

test('the same error in two projects makes two signals with the same group key', function (): void {
    $service = app(SignalIngestService::class);
    $alpha = Project::factory()->create();
    $beta = Project::factory()->create();

    $signalAlpha = $service->ingest($alpha, sao_error_payload());
    $signalBeta = $service->ingest($beta, sao_error_payload());

    expect($signalAlpha->id)->not->toBe($signalBeta->id)
        ->and($signalAlpha->group_key)->toBe($signalBeta->group_key);
});

test('a resolved signal reopens when the error recurs', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_error_payload());
    $signal->update(['state' => SignalState::Resolved]);

    $reopened = $service->ingest($project, sao_error_payload());

    expect($reopened->fresh()->state)->toBe(SignalState::Open);
});

test('an original occurrence time stamps the occurrence and a new signal', function (): void {
    $project = Project::factory()->create();
    $occurredAt = now()->subDays(2)->startOfSecond();

    $signal = app(SignalIngestService::class)->ingest($project, sao_error_payload(), $occurredAt);

    expect($signal->occurrences()->sole()->occurred_at->equalTo($occurredAt))->toBeTrue()
        ->and($signal->fresh()->first_seen_at->equalTo($occurredAt))->toBeTrue()
        ->and($signal->fresh()->last_seen_at->equalTo($occurredAt))->toBeTrue();
});

test('last seen never moves backwards when an older occurrence is recorded', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_error_payload());
    $seenToday = $signal->fresh()->last_seen_at;

    $service->ingest($project, sao_error_payload(), now()->subDays(2));

    expect($signal->fresh()->last_seen_at->equalTo($seenToday))->toBeTrue()
        ->and($signal->fresh()->occurrence_count)->toBe(2);
});

test('last seen moves forward when the occurrence is newer than the stored value', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_error_payload(), now()->subDays(3)->startOfSecond());
    $newer = now()->subDay()->startOfSecond();

    $service->ingest($project, sao_error_payload(), $newer);

    expect($signal->fresh()->last_seen_at->equalTo($newer))->toBeTrue();
});

test('an old occurrence does not fill the rate limit window', function (): void {
    config()->set('sao.signals.max_occurrences_per_window', 1);
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_error_payload(), now()->subDays(2));
    $service->ingest($project, sao_error_payload(), now()->subDays(2));
    $service->ingest($project, sao_error_payload());

    expect($signal->occurrences()->count())->toBe(3);

    $service->ingest($project, sao_error_payload());

    expect($signal->occurrences()->count())->toBe(3);
});

test('first seen moves back when an occurrence older than it is recorded', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_error_payload());
    $older = now()->subDays(2)->startOfSecond();

    $service->ingest($project, sao_error_payload(), $older);

    expect($signal->fresh()->first_seen_at->equalTo($older))->toBeTrue();
});

test('first seen stays when the occurrence is newer than it', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);
    $first = now()->subDays(3)->startOfSecond();

    $signal = $service->ingest($project, sao_error_payload(), $first);
    $service->ingest($project, sao_error_payload(), now()->subDay());

    expect($signal->fresh()->first_seen_at->equalTo($first))->toBeTrue();
});

test('a replayed occurrence records itself but does not reopen a resolved signal', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_error_payload());
    $signal->update(['state' => SignalState::Resolved]);

    $service->ingest($project, sao_error_payload(), now()->subDays(2));

    expect($signal->fresh()->state)->toBe(SignalState::Resolved)
        ->and($signal->fresh()->occurrence_count)->toBe(2)
        ->and($signal->occurrences()->count())->toBe(2);
});

test('a recurrence increments the occurrence count in the database instead of writing a literal', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);
    $signal = $service->ingest($project, sao_error_payload());

    $connection = $signal->getConnection();
    $grammar = $connection->getQueryGrammar();
    $updates = [];
    $connection->listen(function (QueryExecuted $query) use (&$updates, $grammar): void {
        if (str_starts_with(mb_strtolower($query->sql), 'update ' . $grammar->wrapTable(SAOTables::Signals->value))) {
            $updates[] = $query->sql;
        }
    });

    $recurred = $service->ingest($project, sao_error_payload());

    $atomic = $grammar->wrap('occurrence_count') . ' = ' . $grammar->wrap('occurrence_count') . ' + 1';

    expect($updates)->toHaveCount(1)
        ->and($updates[0])->toContain($atomic)
        ->and($recurred->occurrence_count)->toBe(2)
        ->and($recurred->isDirty())->toBeFalse()
        ->and($signal->fresh()->occurrence_count)->toBe(2);
});

test('a signal created concurrently by another ingest is recurred instead of failing', function (): void {
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);
    $groupKey = app(GroupKeyResolver::class)->resolve(sao_error_payload());

    $connection = (new Signal)->getConnection();
    $signals = $connection->getQueryGrammar()->wrapTable(SAOTables::Signals->value);
    $competitorCreated = false;

    // The competing ingest commits its signal between this ingest's lookup (which
    // found nothing) and its insert, as a second process would.
    $connection->listen(function (QueryExecuted $query) use (&$competitorCreated, $signals, $project, $groupKey): void {
        if ($competitorCreated || ! str_starts_with(mb_strtolower($query->sql), 'select') || ! str_contains($query->sql, 'from ' . $signals)) {
            return;
        }

        $competitorCreated = true;
        Signal::factory()->create([
            'project_id' => $project->getKey(),
            'group_key' => $groupKey,
            'state' => SignalState::Open,
            'occurrence_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    });

    $signal = $service->ingest($project, sao_error_payload());

    expect($competitorCreated)->toBeTrue()
        ->and(Signal::query()->where('project_id', $project->getKey())->count())->toBe(1)
        ->and($signal->occurrence_count)->toBe(2)
        ->and($signal->fresh()->occurrence_count)->toBe(2)
        ->and($signal->occurrences()->count())->toBe(1);
});

test('a rate limited occurrence leaves the occurrence count unchanged', function (): void {
    config()->set('sao.signals.max_occurrences_per_window', 1);
    $project = Project::factory()->create();
    $service = app(SignalIngestService::class);

    $signal = $service->ingest($project, sao_error_payload());
    $limited = $service->ingest($project, sao_error_payload());

    expect($limited->occurrence_count)->toBe(1)
        ->and($signal->fresh()->occurrence_count)->toBe(1)
        ->and($signal->occurrences()->count())->toBe(1);
});
