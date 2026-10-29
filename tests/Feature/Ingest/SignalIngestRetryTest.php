<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Ingest\SignalIngestService;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;

/*
 * Laravel retries only an outermost transaction, and RefreshDatabase wraps every
 * test in one, so this file migrates the in-memory database itself and keeps no
 * wrapping transaction. DatabaseMigrations cannot be used: its teardown rollback
 * runs down migrations that SQLite refuses (dropping foreign keys by name). The
 * in-memory database is discarded with the application, so nothing leaks.
 */
beforeEach(function (): void {
    $connection = (new Signal)->getConnection();

    if ($connection->getDriverName() !== 'sqlite' || $connection->getDatabaseName() !== ':memory:') {
        $this->markTestSkipped('Runs without a wrapping transaction, so only on the in-memory SQLite database.');
    }

    $this->artisan('migrate');
});

test('an ingest whose transaction hits a deadlock is retried from scratch without double effects', function (): void {
    $project = Project::factory()->create();
    $connection = (new Signal)->getConnection();
    $occurrences = 'insert into ' . $connection->getQueryGrammar()->wrapTable(SAOTables::SignalOccurrences->value);
    $deadlocks = 0;

    // The first attempt writes the signal, its count and the release, then is
    // chosen as a deadlock victim as InnoDB would do.
    $connection->listen(function (QueryExecuted $query) use (&$deadlocks, $occurrences): void {
        if ($deadlocks === 0 && str_starts_with($query->sql, $occurrences)) {
            $deadlocks++;

            throw new RuntimeException('Deadlock found when trying to get lock; try restarting transaction');
        }
    });

    $signal = app(SignalIngestService::class)->ingest($project, [
        'kind' => 'exception',
        'class' => 'RuntimeException',
        'message' => 'Model failed id=1',
        'version' => '1.4.0',
    ]);

    expect($deadlocks)->toBe(1)
        ->and($connection->transactionLevel())->toBe(0)
        ->and(Signal::query()->count())->toBe(1)
        ->and($signal->occurrence_count)->toBe(1)
        ->and($signal->fresh()->occurrence_count)->toBe(1)
        ->and(SignalOccurrence::query()->count())->toBe(1)
        ->and(Release::query()->count())->toBe(1);
});
