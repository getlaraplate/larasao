<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\WorkflowSchemeFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Exceptions\DuplicateCreationTransitionException;
use Override;

/**
 * Shareable across ticket types and projects — the valve that stops every new
 * type from spawning a new scheme.
 *
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $is_default At most one scheme is the default; setting a new one clears the previous
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class WorkflowScheme extends Model
{
    /**
     * Mirrors the migration default so a new instance reports what it will hold
     * once persisted.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'is_default' => false,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'description',
        'is_default',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::WorkflowSchemes->value;

    public static function default(): ?self
    {
        return self::query()->where('is_default', true)->first();
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $table = SAOTables::WorkflowSchemes->value;

        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255', "unique:{$table},name"],
            'description' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    public function initialTransition(): ?WorkflowTransition
    {
        return $this->transitions()->whereNull('from_status_id')->first();
    }

    /**
     * @return HasMany<WorkflowTransition, $this>
     */
    public function transitions(): HasMany
    {
        return $this->hasMany(WorkflowTransition::class, 'workflow_scheme_id');
    }

    protected static function booted(): void
    {
        WorkflowTransition::creating(static function (WorkflowTransition $transition): void {
            if ($transition->from_status_id !== null) {
                return;
            }

            $exists = WorkflowTransition::query()
                ->where('workflow_scheme_id', $transition->workflow_scheme_id)
                ->whereNull('from_status_id')
                ->exists();

            if ($exists) {
                throw DuplicateCreationTransitionException::make();
            }
        });

        self::saving(static function (self $scheme): void {
            if ($scheme->is_default !== true) {
                return;
            }

            self::query()
                ->when($scheme->exists, static fn (Builder $query): Builder => $query->whereKeyNot($scheme->getKey()))
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }

    /**
     * @return Factory<WorkflowScheme>
     */
    protected static function newFactory(): Factory
    {
        return WorkflowSchemeFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }
}
