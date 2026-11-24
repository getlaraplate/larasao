<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketLink;

/**
 * @extends Factory<TicketLink>
 */
final class TicketLinkFactory extends Factory
{
    /**
     * @var class-string<TicketLink>
     */
    protected $model = TicketLink::class;

    /**
     * @return array<model-property<TicketLink>, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'connection_id' => Connection::factory()->state(['driver_key' => 'fake', 'capabilities' => [Capability::Issues]]),
            'remote_id' => (string) $this->faker->numberBetween(1, 9999),
            'url' => $this->faker->url(),
            'last_synced_at' => null,
            'last_sync_state' => null,
        ];
    }
}
