<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\SignalState;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Signal;

/**
 * @extends Factory<Signal>
 */
final class SignalFactory extends Factory
{
    /**
     * @var class-string<Signal>
     */
    protected $model = Signal::class;

    /**
     * @return array<model-property<Signal>, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'group_key' => $this->faker->unique()->regexify('[0-9a-f]{16}'),
            'algo_version' => 1,
            'state' => SignalState::Open,
            'occurrence_count' => 0,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
