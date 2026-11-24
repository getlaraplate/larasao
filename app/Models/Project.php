<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ProjectFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Exceptions\ImmutableKeyPrefixException;
use Modules\SAO\Models\Pivot\ProjectTicketType;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property string $key_prefix Immutable once the first ticket number has been allocated
 * @property string|null $description
 * @property int $next_ticket_number Allocated under a row lock; gaps are accepted
 * @property bool $is_active
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class Project extends Model implements IActivatableModel
{
    use HasActivation {
        HasActivation::casts as private activationCasts;
    }

    /**
     * Mirrors the database defaults so a freshly created model reports the same
     * values it will have once refreshed. Without this the allocator, which
     * reads next_ticket_number straight after creation, would see null.
     *
     * `is_active` is absent because HasActivation initializes it.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'next_ticket_number' => 0,
    ];

    /**
     * `next_ticket_number` is deliberately absent: only TicketKeyAllocator may
     * move it, and only under a row lock. `is_active` is added by HasActivation.
     *
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'key_prefix',
        'description',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::Projects->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $table = SAOTables::Projects->value;

        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255'],
            'key_prefix' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9]{1,9}$/', "unique:{$table},key_prefix"],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    public function defaultTicketType(): ?TicketType
    {
        return $this->ticketTypes()->wherePivot('is_default', true)->first();
    }

    /**
     * The project's deployment targets — production, staging, and so on.
     *
     * @return HasMany<Environment, $this>
     */
    public function environments(): HasMany
    {
        return $this->hasMany(Environment::class);
    }

    /**
     * The project's integration bindings — one per (connection, capability).
     *
     * @return HasMany<ProjectBinding, $this>
     */
    public function bindings(): HasMany
    {
        return $this->hasMany(ProjectBinding::class);
    }

    /**
     * @return BelongsToMany<TicketType, $this, ProjectTicketType>
     */
    public function ticketTypes(): BelongsToMany
    {
        return $this->belongsToMany(TicketType::class, SAOTables::ProjectTicketTypes->value)
            ->using(ProjectTicketType::class)
            ->withPivot(['is_default', 'workflow_scheme_id'])
            ->withTimestamps();
    }

    protected static function booted(): void
    {
        self::updating(static function (self $project): void {
            if (! $project->isDirty('key_prefix')) {
                return;
            }

            if ((int) $project->getOriginal('next_ticket_number') > 0) {
                throw ImmutableKeyPrefixException::forProject((string) $project->getOriginal('name'));
            }
        });
    }

    /**
     * @return Factory<Project>
     */
    protected static function newFactory(): Factory
    {
        return ProjectFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return array_merge($this->activationCasts(), [
            'next_ticket_number' => 'integer',
        ]);
    }
}
