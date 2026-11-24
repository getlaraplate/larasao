<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\WorkflowScheme;

/**
 * @extends Factory<WorkflowScheme>
 */
final class WorkflowSchemeFactory extends Factory
{
    /**
     * @var class-string<WorkflowScheme>
     */
    protected $model = WorkflowScheme::class;

    /**
     * @return array<model-property<WorkflowScheme>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'description' => $this->faker->sentence(),
            'is_default' => false,
        ];
    }

    public function isDefault(): self
    {
        return $this->state(fn (): array => ['is_default' => true]);
    }
}
