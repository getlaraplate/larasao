<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;
use Modules\SAO\Services\VersionCensusService;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(VersionCensusService::class);
    $this->project = Project::factory()->create();
});

test('a normalizable version is censused once as an observed release', function (): void {
    $first = $this->service->record($this->project->getKey(), 'v1.4.0');
    $second = $this->service->record($this->project->getKey(), '1.4.0 (build 123)');

    expect($first->release)->not->toBeNull()
        ->and($first->release->status)->toBe(ReleaseStatus::Observed)
        ->and($first->release->version)->toBe('1.4.0')
        ->and($second->release->getKey())->toBe($first->release->getKey())
        ->and(Release::query()->count())->toBe(1);
});

test('a pre-release is censused under its product version but keeps its precise string', function (): void {
    $result = $this->service->record($this->project->getKey(), '1.4.0-rc.1');

    expect($result->affectedVersion)->toBe('1.4.0-rc.1')
        ->and($result->release->version)->toBe('1.4.0');
});

test('an unnormalizable version is kept raw but not promoted', function (): void {
    $result = $this->service->record($this->project->getKey(), 'nightly');

    expect($result->affectedVersion)->toBe('nightly')
        ->and($result->release)->toBeNull()
        ->and(Release::query()->count())->toBe(0);
});

test('censusing a version never downgrades a release a maintainer curated', function (): void {
    Release::factory()->for($this->project)->shipped()->create(['version' => '1.4.0']);

    $result = $this->service->record($this->project->getKey(), '1.4.0');

    expect($result->release->status)->toBe(ReleaseStatus::Shipped)
        ->and(Release::query()->count())->toBe(1);
});

test('a release created concurrently by another ingest is reused instead of failing', function (): void {
    $connection = (new Release)->getConnection();
    $releases = 'from ' . $connection->getQueryGrammar()->wrapTable(SAOTables::Releases->value);
    $competitor = null;

    // The competing ingest commits the release between this census lookup (which
    // found nothing) and its insert, as a second process would.
    $connection->listen(function (QueryExecuted $query) use (&$competitor, $releases): void {
        if ($competitor !== null || ! str_starts_with(mb_strtolower($query->sql), 'select') || ! str_contains($query->sql, $releases)) {
            return;
        }

        $competitor = Release::factory()->for($this->project)->create([
            'version' => '1.4.0',
            'status' => ReleaseStatus::Observed,
        ]);
    });

    $result = $this->service->record($this->project->getKey(), '1.4.0');

    expect($competitor)->not->toBeNull()
        ->and($result->release->getKey())->toBe($competitor->getKey())
        ->and(Release::query()->count())->toBe(1);
});
