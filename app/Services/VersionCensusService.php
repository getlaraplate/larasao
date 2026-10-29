<?php

declare(strict_types=1);

namespace Modules\SAO\Services;

use Illuminate\Database\UniqueConstraintViolationException;
use Modules\SAO\Data\VersionCensus;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Ingest\VersionNormalizer;
use Modules\SAO\Models\Release;

/**
 * Records a reported version into the per-project version census. A normalizable
 * version is deduped into a single {@see Release} row per product version,
 * created as `observed` when new — never downgrading a release a maintainer has
 * already curated. An unnormalizable string is kept raw for audit but not
 * promoted, so garbage cannot fill the census with near-duplicates.
 */
final readonly class VersionCensusService
{
    public function __construct(private VersionNormalizer $normalizer) {}

    public function record(int $projectId, ?string $rawVersion): VersionCensus
    {
        if ($rawVersion === null) {
            return VersionCensus::empty();
        }

        $normalized = $this->normalizer->normalize($rawVersion);

        if ($normalized === null) {
            $raw = mb_trim($rawVersion);

            return new VersionCensus($raw === '' ? null : $raw, null);
        }

        $release = $this->release($projectId, $this->normalizer->productVersion($normalized));

        return new VersionCensus($normalized, $release);
    }

    /**
     * Finds the release or creates it as observed. The insert runs in a savepoint: when a concurrent ingest
     * created the same release after the lookup, the unique key on (project_id, version) refuses this one,
     * the savepoint is rolled back without aborting the enclosing ingest transaction, and the winner's row is
     * read with a shared locking read. Being a locking read, it sees the latest committed row where a plain read
     * inside the enclosing transaction would still see its snapshot (InnoDB, REPEATABLE READ); being shared, it
     * is compatible with the shared lock the refused insert already holds on that row, so several losers of the
     * same new release do not deadlock asking for an exclusive lock the census does not need (it only reads).
     */
    private function release(int $projectId, string $version): Release
    {
        $attributes = ['project_id' => $projectId, 'version' => $version];
        $existing = Release::query()->where($attributes)->first();

        if ($existing instanceof Release) {
            return $existing;
        }

        try {
            return (new Release)->getConnection()->transaction(static fn (): Release => Release::query()->create([
                ...$attributes,
                'status' => ReleaseStatus::Observed,
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            return Release::query()->where($attributes)->sharedLock()->first() ?? throw $exception;
        }
    }
}
