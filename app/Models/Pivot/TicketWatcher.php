<?php

declare(strict_types=1);

namespace Modules\SAO\Models\Pivot;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Pivot;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Models\Ticket;
use Override;

/**
 * A user watching a ticket. Modelled explicitly so when a watch started (or
 * ended, on detach) is recorded and observable.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 */
final class TicketWatcher extends Pivot
{
    #[Override]
    public $incrementing = true;

    #[Override]
    public $timestamps = true;

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::TicketWatchers->value;

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'user_id' => 'integer',
        ];
    }
}
