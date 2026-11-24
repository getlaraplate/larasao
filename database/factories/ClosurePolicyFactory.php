<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\ClosureAction;
use Modules\SAO\Models\ClosurePolicy;
use Modules\SAO\Models\Project;

/**
 * @extends Factory<ClosurePolicy>
 */
final class ClosurePolicyFactory extends Factory
{
    /**
     * @var class-string<ClosurePolicy>
     */
    protected $model = ClosurePolicy::class;

    /**
     * @return array<model-property<ClosurePolicy>, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => $this->faker->unique()->words(2, true),
            'conditions' => [
                ['key' => 'fix_deployed_there'],
                ['key' => 'no_recurrence_for', 'config' => ['days' => 14]],
            ],
            'action' => ClosureAction::Propose,
            'is_active' => true,
        ];
    }

    public function closes(): self
    {
        return $this->state(fn (): array => ['action' => ClosureAction::Close]);
    }
}
