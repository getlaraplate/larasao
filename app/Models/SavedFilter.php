<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\SavedFilterFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Services\TicketSearchCriteria;
use Override;

/**
 * A user's stored ticket search. `criteria` holds the serialised
 * {@see TicketSearchCriteria}; a null `project_id` means the filter spans every
 * project the user can see.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $project_id Null means the filter spans every project the user can see
 * @property string $name
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed> $criteria
 */
final class SavedFilter extends Model
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'user_id',
        'project_id',
        'name',
        'criteria',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::SavedFilters->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $rules['create'] = array_merge($rules['create'], [
            'user_id' => ['required', 'integer', 'exists:' . CoreTables::Users->value . ',id'],
            'project_id' => ['nullable', 'integer', 'exists:' . SAOTables::Projects->value . ',id'],
            'name' => ['required', 'string', 'max:255'],
            'criteria' => ['required', 'array'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255'],
            'criteria' => ['sometimes', 'array'],
        ]);

        return $rules;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function toCriteria(): TicketSearchCriteria
    {
        return TicketSearchCriteria::fromArray($this->criteria);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Factory<SavedFilter>
     */
    protected static function newFactory(): Factory
    {
        return SavedFilterFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'project_id' => 'integer',
            'criteria' => 'array',
        ];
    }
}
