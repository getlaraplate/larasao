<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalOccurrence;

/**
 * @extends Factory<SignalOccurrence>
 */
final class SignalOccurrenceFactory extends Factory
{
    /**
     * @var class-string<SignalOccurrence>
     */
    protected $model = SignalOccurrence::class;

    /**
     * @return array<model-property<SignalOccurrence>, mixed>
     */
    public function definition(): array
    {
        return [
            'signal_id' => Signal::factory(),
            'environment' => $this->faker->randomElement(['production', 'staging']),
            'context' => null,
            'occurred_at' => now(),
        ];
    }
}
