<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\User;
use Modules\SAO\Models\ContributorIdentity;

/**
 * @extends Factory<ContributorIdentity>
 */
final class ContributorIdentityFactory extends Factory
{
    /**
     * @var class-string<ContributorIdentity>
     */
    protected $model = ContributorIdentity::class;

    /**
     * @return array<model-property<ContributorIdentity>, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => 'github',
            'identity' => $this->faker->unique()->userName(),
        ];
    }

    public function forProvider(string $provider): self
    {
        return $this->state(fn (): array => ['provider' => $provider]);
    }

    public function anyProvider(): self
    {
        return $this->state(fn (): array => ['provider' => ContributorIdentity::ANY_PROVIDER]);
    }
}
