<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Concerns\SortableTrait;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\TicketStatusFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\StatusCategory;
use Override;
use Spatie\EloquentSortable\Sortable;

/**
 * A status is global to the installation. Workflow schemes compose them, so
 * "In review" is defined once rather than once per scheme.
 *
 * @property int $id
 * @property string $name
 * @property StatusCategory $category Canonical meaning; phase 3 maps onto this, not onto the name
 * @property string $colour
 * @property int $order_column The display order of the status
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class TicketStatus extends Model implements Sortable
{
    use SortableTrait;

    /**
     * Mirrors the migration defaults so a new instance reports what it will hold
     * once persisted. `order_column` is assigned by the sortable trait.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'colour' => 'gray',
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'category',
        'colour',
        'order_column',
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $sortable = [
        'order_column_name' => 'order_column',
        'sort_when_creating' => true,
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::TicketStatuses->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $table = SAOTables::TicketStatuses->value;

        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255', "unique:{$table},name"],
            'category' => ['required', 'string', StatusCategory::validationRule()],
            'colour' => ['sometimes', 'string', 'max:16'],
            'order_column' => ['sometimes', 'integer', 'min:0'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', StatusCategory::validationRule()],
            'colour' => ['sometimes', 'string', 'max:16'],
            'order_column' => ['sometimes', 'integer', 'min:0'],
        ]);

        return $rules;
    }

    /**
     * @return Factory<TicketStatus>
     */
    protected static function newFactory(): Factory
    {
        return TicketStatusFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'category' => StatusCategory::class,
            'order_column' => 'integer',
        ];
    }
}
