<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\Label;
use Modules\SAO\Models\Project;

/**
 * @extends Factory<Label>
 */
final class LabelFactory extends Factory
{
    /**
     * @var class-string<Label>
     */
    protected $model = Label::class;

    /**
     * @return array<model-property<Label>, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => $this->faker->unique()->word(),
            'colour' => $this->faker->randomElement(['gray', 'red', 'green', 'blue', 'amber']),
        ];
    }
}
