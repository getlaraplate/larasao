<?php

declare(strict_types=1);

namespace Modules\SAO\Models\Pivot;

use Modules\Core\Overrides\Pivot;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * Which types a project offers, which of them is preselected, and — optionally —
 * a workflow scheme that replaces the type's own for this project alone.
 *
 * @property int $id
 * @property int $project_id
 * @property int $ticket_type_id
 * @property bool $is_default
 * @property int|null $workflow_scheme_id Optional per-project override; null means the type own scheme applies
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class ProjectTicketType extends Pivot
{
    public $incrementing = true;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'is_default' => false,
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::ProjectTicketTypes->value;

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'ticket_type_id' => 'integer',
            'is_default' => 'boolean',
            'workflow_scheme_id' => 'integer',
        ];
    }
}
