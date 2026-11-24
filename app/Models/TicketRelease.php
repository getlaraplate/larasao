<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\TicketReleaseFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\TicketReleaseState;
use Override;

/**
 * Attributes a ticket to a release as `promised` or `shipped`. The pair
 * (ticket, release) is unique and the state is deliberately independent of the
 * ticket's own workflow status: a fix can be shipped in a release while its
 * ticket is still open, and vice versa.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $release_id
 * @property TicketReleaseState $state
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class TicketRelease extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'ticket_id',
        'release_id',
        'state',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::TicketReleases->value;

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'ticket';
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return Factory<TicketRelease>
     */
    protected static function newFactory(): Factory
    {
        return TicketReleaseFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'release_id' => 'integer',
            'state' => TicketReleaseState::class,
        ];
    }
}
