<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\TicketRelationFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\TicketRelationType;
use Modules\SAO\Exceptions\SelfTicketRelationException;
use Override;

/**
 * A typed link from one ticket to another. Direction is carried by
 * source/target; the meaning of the direction comes from {@see TicketRelationType}.
 *
 * @property int $id
 * @property int $source_ticket_id
 * @property int $target_ticket_id
 * @property TicketRelationType $type
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class TicketRelation extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'source_ticket_id',
        'target_ticket_id',
        'type',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::TicketRelations->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $tickets = SAOTables::Tickets->value;

        $rules['create'] = array_merge($rules['create'], [
            'source_ticket_id' => ['required', 'integer', "exists:{$tickets},id"],
            'target_ticket_id' => ['required', 'integer', "exists:{$tickets},id"],
            'type' => ['required', 'string', TicketRelationType::validationRule()],
        ]);

        return $rules;
    }

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'source';
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'source_ticket_id');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function target(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'target_ticket_id');
    }

    protected static function booted(): void
    {
        parent::booted();

        self::creating(static function (self $relation): void {
            if ($relation->source_ticket_id === $relation->target_ticket_id) {
                throw SelfTicketRelationException::make();
            }
        });
    }

    /**
     * @return Factory<TicketRelation>
     */
    protected static function newFactory(): Factory
    {
        return TicketRelationFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'source_ticket_id' => 'integer',
            'target_ticket_id' => 'integer',
            'type' => TicketRelationType::class,
        ];
    }
}
