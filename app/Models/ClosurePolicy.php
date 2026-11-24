<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ClosurePolicyFactory;
use Modules\SAO\Enums\ClosureAction;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * A project-scoped set of composable closure conditions and the action taken
 * when they all hold. The conditions are stored as plain `{key, config}` json
 * and built into predicates by `ClosureConditionRegistry`, so a policy is data,
 * not code.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property ClosureAction $action
 * @property bool $is_active
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property list<array{key: string, config?: array<string, mixed>}> $conditions Ordered list of {key, config} combined with AND
 */
final class ClosurePolicy extends Model implements IActivatableModel
{
    use HasActivation;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'action' => ClosureAction::Propose->value,
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'project_id',
        'name',
        'conditions',
        'action',
        'is_active',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::ClosurePolicies->value;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return Factory<ClosurePolicy>
     */
    protected static function newFactory(): Factory
    {
        return ClosurePolicyFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'conditions' => 'array',
            'action' => ClosureAction::class,
            'is_active' => 'boolean',
        ];
    }
}
