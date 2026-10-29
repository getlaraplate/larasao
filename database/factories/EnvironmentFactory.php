<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;

/**
 * @extends Factory<Environment>
 */
final class EnvironmentFactory extends Factory
{
    /**
     * @var class-string<Environment>
     */
    protected $model = Environment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => $this->faker->unique()->randomElement(['production', 'staging', 'qa', 'sandbox']),
            'base_url' => null,
            'current_version' => null,
            'current_commit' => null,
            'current_branch' => null,
            'last_seen_at' => null,
        ];
    }
}
