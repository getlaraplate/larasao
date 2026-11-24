<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Enums\CoreTables;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\WorkflowTransitionFactory;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * One permitted move within a scheme. A null `from_status_id` is the creation
 * transition, which is how a scheme declares the status a new ticket starts in.
 *
 * @property int $id
 * @property int $workflow_scheme_id
 * @property int|null $from_status_id Null means the creation transition: it declares the scheme initial status
 * @property int $to_status_id
 * @property string $label
 * @property string|null $required_permission
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class WorkflowTransition extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'workflow_scheme_id',
        'from_status_id',
        'to_status_id',
        'label',
        'required_permission',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::WorkflowTransitions->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $statuses = SAOTables::TicketStatuses->value;

        // The value is handed straight to `Gate::allows()` by WorkflowService, which
        // fails closed on a name nobody holds — and on a name that does not exist at
        // all. A typo here used to deny the transition for everybody, for good, without
        // saying so, so the name is checked against the permission table on the way in.
        $permissions = (string) config('permission.table_names.permissions', CoreTables::Permissions->value);
        $permission_rules = ['nullable', 'string', 'max:255', "exists:{$permissions},name"];

        $rules['create'] = array_merge($rules['create'], [
            'workflow_scheme_id' => ['required', 'integer', 'exists:' . SAOTables::WorkflowSchemes->value . ',id'],
            'from_status_id' => ['nullable', 'integer', "exists:{$statuses},id"],
            'to_status_id' => ['required', 'integer', "exists:{$statuses},id"],
            'label' => ['required', 'string', 'max:255'],
            'required_permission' => $permission_rules,
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'label' => ['sometimes', 'string', 'max:255'],
            'required_permission' => $permission_rules,
        ]);

        return $rules;
    }

    public function isInitial(): bool
    {
        return $this->from_status_id === null;
    }

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'scheme';
    }

    /**
     * @return BelongsTo<WorkflowScheme, $this>
     */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(WorkflowScheme::class, 'workflow_scheme_id');
    }

    /**
     * @return BelongsTo<TicketStatus, $this>
     */
    public function fromStatus(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'from_status_id');
    }

    /**
     * @return BelongsTo<TicketStatus, $this>
     */
    public function toStatus(): BelongsTo
    {
        return $this->belongsTo(TicketStatus::class, 'to_status_id');
    }

    /**
     * @return Factory<WorkflowTransition>
     */
    protected static function newFactory(): Factory
    {
        return WorkflowTransitionFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'workflow_scheme_id' => 'integer',
            'from_status_id' => 'integer',
            'to_status_id' => 'integer',
        ];
    }
}
