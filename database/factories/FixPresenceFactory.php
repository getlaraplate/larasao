<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\FixPresence;

/**
 * @extends Factory<FixPresence>
 */
final class FixPresenceFactory extends Factory
{
    /**
     * @var class-string<FixPresence>
     */
    protected $model = FixPresence::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'change_ref_id' => ChangeRef::factory(),
            'environment_id' => Environment::factory(),
            'deployed_commit' => $this->faker->regexify('[0-9a-f]{40}'),
            'contained' => true,
            'checked_at' => now(),
        ];
    }

    public function unknown(): self
    {
        return $this->state(fn (): array => [
            'contained' => null,
        ]);
    }
}
