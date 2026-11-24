<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Release;

/**
 * @extends Factory<Release>
 */
final class ReleaseFactory extends Factory
{
    /**
     * @var class-string<Release>
     */
    protected $model = Release::class;

    /**
     * @return array<model-property<Release>, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'version' => $this->faker->unique()->numerify('#.#.#'),
            'status' => ReleaseStatus::Announced,
            'released_at' => null,
        ];
    }

    public function shipped(): self
    {
        return $this->state(fn (): array => [
            'status' => ReleaseStatus::Shipped,
            'released_at' => now(),
        ]);
    }

    public function observed(): self
    {
        return $this->state(fn (): array => [
            'status' => ReleaseStatus::Observed,
            'released_at' => null,
        ]);
    }
}
