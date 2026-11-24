<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\TicketType;
use Modules\SAO\Models\WorkflowScheme;

/**
 * @extends Factory<TicketType>
 */
final class TicketTypeFactory extends Factory
{
    /**
     * @var class-string<TicketType>
     */
    protected $model = TicketType::class;

    /**
     * @return array<model-property<TicketType>, mixed>
     */
    public function definition(): array
    {
        $name = $this->faker->unique()->word();

        return [
            'name' => ucfirst($name),
            'slug' => $name,
            'icon' => null,
            'colour' => 'gray',
            'workflow_scheme_id' => WorkflowScheme::factory(),
            'is_defect' => false,
        ];
    }

    public function defect(): self
    {
        return $this->state(fn (): array => ['is_defect' => true]);
    }
}
