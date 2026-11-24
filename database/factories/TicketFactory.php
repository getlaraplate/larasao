<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\TicketPriority;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Models\TicketStatus;
use Modules\SAO\Models\TicketType;
use Modules\SAO\Services\TicketKeyAllocator;

/**
 * @extends Factory<Ticket>
 */
final class TicketFactory extends Factory
{
    /**
     * @var class-string<Ticket>
     */
    protected $model = Ticket::class;

    /**
     * @return array<model-property<Ticket>, mixed>
     */
    public function definition(): array
    {
        $project = Project::factory()->create();
        $allocated = app(TicketKeyAllocator::class)->allocate($project);

        return [
            'project_id' => $project->id,
            'number' => $allocated['number'],
            'key' => $allocated['key'],
            'ticket_type_id' => TicketType::factory(),
            'ticket_status_id' => TicketStatus::factory(),
            'priority' => TicketPriority::Normal,
            'title' => $this->faker->sentence(),
            'description' => $this->faker->paragraph(),
            'reporter_id' => null,
            'assignee_id' => null,
        ];
    }

    /**
     * Allocates the key from an existing project instead of creating a new one.
     */
    public function forProject(Project $project): self
    {
        $allocated = app(TicketKeyAllocator::class)->allocate($project);

        return $this->state(fn (): array => [
            'project_id' => $project->id,
            'number' => $allocated['number'],
            'key' => $allocated['key'],
        ]);
    }
}
