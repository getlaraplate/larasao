<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\TicketStatus;
use Modules\SAO\Models\WorkflowScheme;
use Modules\SAO\Models\WorkflowTransition;

/**
 * @extends Factory<WorkflowTransition>
 */
final class WorkflowTransitionFactory extends Factory
{
    /**
     * @var class-string<WorkflowTransition>
     */
    protected $model = WorkflowTransition::class;

    /**
     * @return array<model-property<WorkflowTransition>, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_scheme_id' => WorkflowScheme::factory(),
            'from_status_id' => TicketStatus::factory(),
            'to_status_id' => TicketStatus::factory(),
            'label' => $this->faker->words(2, true),
            'required_permission' => null,
        ];
    }
}
