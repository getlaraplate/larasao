<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\EnvironmentFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Jobs\CheckFixPresenceJob;
use Override;

/**
 * A deployment target of a project — production, staging, and so on. It records
 * the version last seen running and when, so the deploy census can answer "what
 * runs where" with an honest freshness rather than an assumed one.
 */
final class Environment extends Model
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'project_id',
        'name',
        'base_url',
        'current_version',
        'current_commit',
        'current_branch',
        'last_seen_at',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::Environments->value;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * A new deployed commit queues a check of which fixes it contains. Queued after
     * commit: a change inside a transaction that rolls back (a dry-run reprocess, a
     * retried ingest attempt) queues nothing, and a committed one queues once.
     * `created`/`updated` rather than `saved`, because they fire only when a row was
     * written, so a later no-op save cannot re-queue an earlier change.
     */
    #[Override]
    protected static function booted(): void
    {
        parent::booted();

        self::created(static function (Environment $environment): void {
            if ($environment->current_commit !== null && $environment->current_commit !== '') {
                $environment->queueFixPresenceCheck();
            }
        });

        self::updated(static function (Environment $environment): void {
            if ($environment->wasChanged('current_commit') && $environment->current_commit !== null && $environment->current_commit !== '') {
                $environment->queueFixPresenceCheck();
            }
        });
    }

    /**
     * @return Factory<Environment>
     */
    protected static function newFactory(): Factory
    {
        return EnvironmentFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'last_seen_at' => 'datetime',
        ];
    }

    private function queueFixPresenceCheck(): void
    {
        $id = (int) $this->getKey();

        $this->getConnection()->afterCommit(static function () use ($id): void {
            dispatch(CheckFixPresenceJob::forEnvironment($id));
        });
    }
}
