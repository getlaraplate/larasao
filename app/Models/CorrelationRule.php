<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\CorrelationRuleFactory;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\MatchType;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * An operator-authored correlation rule: when the canonical field named by
 * `target` matches `pattern`, the event belongs to `project` (optionally with
 * an `environment`). Rules are evaluated by priority, lower first, then by id,
 * and may be scoped to one connection and/or one source profile.
 */
final class CorrelationRule extends Model implements IActivatableModel
{
    use HasActivation;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'priority' => 100,
        'match' => 'exact',
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'priority',
        'is_active',
        'target',
        'match',
        'pattern',
        'connection_id',
        'source_profile_id',
        'project_id',
        'environment',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::CorrelationRules->value;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<Connection, $this>
     */
    public function remoteConnection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * @return BelongsTo<SourceProfile, $this>
     */
    public function sourceProfile(): BelongsTo
    {
        return $this->belongsTo(SourceProfile::class);
    }

    /**
     * @return Factory<CorrelationRule>
     */
    protected static function newFactory(): Factory
    {
        return CorrelationRuleFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'priority' => 'integer',
            'is_active' => 'boolean',
            'target' => CorrelationTarget::class,
            'match' => MatchType::class,
            'connection_id' => 'integer',
            'source_profile_id' => 'integer',
            'project_id' => 'integer',
        ];
    }
}
