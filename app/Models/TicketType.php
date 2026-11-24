<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\TicketTypeFactory;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * Types are global and enabled per project through a pivot, so that "bug" is
 * defined once rather than once per project.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $icon
 * @property string $colour
 * @property int $workflow_scheme_id
 * @property bool $is_defect Machine-readable hook: phase 2 creates tickets of this type from errors
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class TicketType extends Model
{
    /**
     * Mirrors the migration defaults so a new instance reports what it will hold
     * once persisted.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'colour' => 'gray',
        'is_defect' => false,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'slug',
        'icon',
        'colour',
        'workflow_scheme_id',
        'is_defect',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::TicketTypes->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $table = SAOTables::TicketTypes->value;

        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:64', "unique:{$table},slug"],
            'icon' => ['nullable', 'string', 'max:64'],
            'colour' => ['sometimes', 'string', 'max:16'],
            'workflow_scheme_id' => ['required', 'integer', 'exists:' . SAOTables::WorkflowSchemes->value . ',id'],
            'is_defect' => ['sometimes', 'boolean'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:64'],
            'colour' => ['sometimes', 'string', 'max:16'],
            'workflow_scheme_id' => ['sometimes', 'integer', 'exists:' . SAOTables::WorkflowSchemes->value . ',id'],
            'is_defect' => ['sometimes', 'boolean'],
        ]);

        return $rules;
    }

    /**
     * @return BelongsTo<WorkflowScheme, $this>
     */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(WorkflowScheme::class, 'workflow_scheme_id');
    }

    /**
     * @return Factory<TicketType>
     */
    protected static function newFactory(): Factory
    {
        return TicketTypeFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'workflow_scheme_id' => 'integer',
            'is_defect' => 'boolean',
        ];
    }
}
