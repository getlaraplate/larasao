<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\Signal;
use Modules\SAO\Models\SignalAlias;

/**
 * @extends Factory<SignalAlias>
 */
final class SignalAliasFactory extends Factory
{
    /**
     * @var class-string<SignalAlias>
     */
    protected $model = SignalAlias::class;

    /**
     * @return array<model-property<SignalAlias>, mixed>
     */
    public function definition(): array
    {
        return [
            'signal_id' => Signal::factory(),
            'group_key' => $this->faker->unique()->regexify('[0-9a-f]{16}'),
            'algo_version' => 1,
        ];
    }
}
