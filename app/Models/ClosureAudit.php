<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ClosureAuditFactory;
use Modules\SAO\Enums\ClosureAction;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * The record of an automatic or proposed closure: which conditions held with
 * what evidence ("closed because"), and — when a signal reappears — the reopen
 * that marks it a premature closure ("returned after"). This is what makes an
 * automatic state change auditable and reversible, and the data that tells
 * whether configured durations are tuned correctly.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $closure_policy_id
 * @property ClosureAction $action
 * @property string|null $reporting_environment
 * @property \Carbon\CarbonImmutable $closed_at
 * @property \Carbon\CarbonImmutable|null $reopened_at
 * @property int|null $returned_after_seconds
 * @property int|null $returned_occurrence_id
 * @property bool $is_premature
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed> $conditions_held The per-condition outcomes and evidence — the "closed because"
 */
final class ClosureAudit extends Model
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'is_premature' => false,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'ticket_id',
        'closure_policy_id',
        'action',
        'conditions_held',
        'reporting_environment',
        'closed_at',
        'reopened_at',
        'returned_after_seconds',
        'returned_occurrence_id',
        'is_premature',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::ClosureAudits->value;

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<ClosurePolicy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(ClosurePolicy::class, 'closure_policy_id');
    }

    /**
     * @return BelongsTo<SignalOccurrence, $this>
     */
    public function returnedOccurrence(): BelongsTo
    {
        return $this->belongsTo(SignalOccurrence::class, 'returned_occurrence_id');
    }

    /**
     * @return Factory<ClosureAudit>
     */
    protected static function newFactory(): Factory
    {
        return ClosureAuditFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'closure_policy_id' => 'integer',
            'action' => ClosureAction::class,
            'conditions_held' => 'array',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'returned_after_seconds' => 'integer',
            'returned_occurrence_id' => 'integer',
            'is_premature' => 'boolean',
        ];
    }
}
