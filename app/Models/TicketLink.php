<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\TicketLinkFactory;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * Links a SAO ticket to its counterpart in an external tracker. A ticket with no
 * link is internal; SAO remains authoritative for internal tickets.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $connection_id
 * @property string $remote_id Identifier of the linked object in the external tracker
 * @property string|null $url
 * @property \Carbon\CarbonImmutable|null $last_synced_at
 * @property string|null $last_sync_state
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class TicketLink extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'ticket_id',
        'connection_id',
        'remote_id',
        'url',
        'last_synced_at',
        'last_sync_state',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::TicketLinks->value;

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
     * Named `remoteConnection` to avoid colliding with Eloquent's built-in
     * `$connection` property.
     *
     * @return BelongsTo<Connection, $this>
     */
    public function remoteConnection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $rules['create'] = array_merge($rules['create'], [
            'ticket_id' => ['required', 'integer', 'exists:' . SAOTables::Tickets->value . ',id'],
            'connection_id' => ['required', 'integer', 'exists:' . SAOTables::Connections->value . ',id'],
            'remote_id' => ['required', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'last_sync_state' => ['nullable', 'string', 'max:255'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'url' => ['nullable', 'string', 'max:2048'],
            'last_sync_state' => ['nullable', 'string', 'max:255'],
        ]);

        return $rules;
    }

    /**
     * @return Factory<TicketLink>
     */
    protected static function newFactory(): Factory
    {
        return TicketLinkFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'last_synced_at' => 'datetime',
        ];
    }
}
