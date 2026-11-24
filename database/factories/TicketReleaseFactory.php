<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\TicketReleaseState;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketRelease;

/**
 * @extends Factory<TicketRelease>
 */
final class TicketReleaseFactory extends Factory
{
    /**
     * @var class-string<TicketRelease>
     */
    protected $model = TicketRelease::class;

    /**
     * @return array<model-property<TicketRelease>, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'release_id' => Release::factory(),
            'state' => TicketReleaseState::Promised,
        ];
    }

    public function shipped(): self
    {
        return $this->state(fn (): array => [
            'state' => TicketReleaseState::Shipped,
        ]);
    }
}
