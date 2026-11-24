<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\TicketRelationType;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketRelation;

/**
 * @extends Factory<TicketRelation>
 */
final class TicketRelationFactory extends Factory
{
    /**
     * @var class-string<TicketRelation>
     */
    protected $model = TicketRelation::class;

    /**
     * @return array<model-property<TicketRelation>, mixed>
     */
    public function definition(): array
    {
        return [
            'source_ticket_id' => Ticket::factory(),
            'target_ticket_id' => Ticket::factory(),
            'type' => $this->faker->randomElement(TicketRelationType::cases()),
        ];
    }
}
