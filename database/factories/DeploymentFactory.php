<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\DeploymentStatus;
use Modules\SAO\Models\Deployment;
use Modules\SAO\Models\Project;

/**
 * @extends Factory<Deployment>
 */
final class DeploymentFactory extends Factory
{
    /**
     * @var class-string<Deployment>
     */
    protected $model = Deployment::class;

    /**
     * @return array<model-property<Deployment>, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'environment_id' => null,
            'release_id' => null,
            'connection_id' => null,
            'version' => $this->faker->unique()->numerify('#.#.#'),
            'status' => DeploymentStatus::Started,
            'external_id' => $this->faker->unique()->uuid(),
            'started_at' => now(),
            'finished_at' => null,
            'meta' => null,
        ];
    }

    public function succeeded(): self
    {
        return $this->state(fn (): array => [
            'status' => DeploymentStatus::Succeeded,
            'finished_at' => now(),
        ]);
    }

    public function rolledBack(): self
    {
        return $this->state(fn (): array => [
            'status' => DeploymentStatus::RolledBack,
            'finished_at' => now(),
        ]);
    }
}
