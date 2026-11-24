<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\IngestEventFactory;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * The record of one received delivery: its raw payload, the profile that
 * matched, the correlation rule that won, the resulting signal, and — always —
 * an explicit status and outcome. It is what makes silence auditable: every
 * discard says why, without reading application logs.
 *
 * @property int $id
 * @property int|null $connection_id Null for the internal (host-app) source
 * @property IngestPipeline $pipeline The ingest path that recorded the event: profile, logs, deploy or code
 * @property string $delivery_id The id the source assigned to this delivery
 * @property int|null $source_profile_id
 * @property IngestStatus $status
 * @property string|null $outcome Explicit reason for the status (reliable silence)
 * @property int|null $project_id
 * @property string|null $winning_rule Which correlation rule attached the event
 * @property int|null $signal_id
 * @property \Carbon\CarbonImmutable|null $reprocessed_at When the event was last replayed through its pipeline
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class IngestEvent extends Model
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'connection_id',
        'pipeline',
        'delivery_id',
        'payload',
        'source_profile_id',
        'status',
        'outcome',
        'project_id',
        'winning_rule',
        'signal_id',
        'reprocessed_at',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::IngestEvents->value;

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return BelongsTo<SourceProfile, $this>
     */
    public function sourceProfile(): BelongsTo
    {
        return $this->belongsTo(SourceProfile::class);
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    /**
     * @return Factory<IngestEvent>
     */
    protected static function newFactory(): Factory
    {
        return IngestEventFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'connection_id' => 'integer',
            'pipeline' => IngestPipeline::class,
            'payload' => 'array',
            'source_profile_id' => 'integer',
            'status' => IngestStatus::class,
            'project_id' => 'integer',
            'signal_id' => 'integer',
            'reprocessed_at' => 'datetime',
        ];
    }
}
