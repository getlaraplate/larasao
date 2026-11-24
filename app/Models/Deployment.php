<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\DeploymentFactory;
use Modules\SAO\Enums\DeploymentStatus;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * A recorded deploy/rollout of a version to an environment. It is the durable,
 * idempotent history behind the deploy census (which becomes a projection of
 * these events) and the precise time anchor release-health reads from: a
 * terminal `succeeded` deployment's `finished_at` is when that version started
 * running. The pair (connection, external_id) is unique so a re-delivery is
 * recorded once.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $environment_id
 * @property int|null $release_id
 * @property int|null $connection_id
 * @property string $version The deployed version as reported, authoritative even without a release_id
 * @property DeploymentStatus $status
 * @property string|null $external_id Source id / delivery id — idempotency key together with connection_id
 * @property \Carbon\CarbonImmutable $started_at When the deploy/rollout began
 * @property \Carbon\CarbonImmutable|null $finished_at Terminal timestamp — the window anchor for release health
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class Deployment extends Model
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'status' => DeploymentStatus::Started->value,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'project_id',
        'environment_id',
        'release_id',
        'connection_id',
        'version',
        'status',
        'external_id',
        'started_at',
        'finished_at',
        'meta',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::Deployments->value;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * @return Factory<Deployment>
     */
    protected static function newFactory(): Factory
    {
        return DeploymentFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'environment_id' => 'integer',
            'release_id' => 'integer',
            'connection_id' => 'integer',
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'meta' => 'array',
        ];
    }
}
