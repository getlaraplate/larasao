<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\ReleaseTagKind;
use Modules\SAO\Models\Release;
use Modules\SAO\Models\ReleaseTag;

/**
 * @extends Factory<ReleaseTag>
 */
final class ReleaseTagFactory extends Factory
{
    /**
     * @var class-string<ReleaseTag>
     */
    protected $model = ReleaseTag::class;

    /**
     * @return array<model-property<ReleaseTag>, mixed>
     */
    public function definition(): array
    {
        return [
            'release_id' => Release::factory(),
            'tag' => 'v' . $this->faker->unique()->numerify('#.#.#'),
            'kind' => ReleaseTagKind::Stable,
        ];
    }

    public function candidate(): self
    {
        return $this->state(fn (): array => [
            'tag' => 'v' . $this->faker->unique()->numerify('#.#.#') . '-rc.1',
            'kind' => ReleaseTagKind::Candidate,
        ]);
    }

    public function alpha(): self
    {
        return $this->state(fn (): array => [
            'tag' => 'v' . $this->faker->unique()->numerify('#.#.#') . '-alpha.1',
            'kind' => ReleaseTagKind::Alpha,
        ]);
    }

    public function beta(): self
    {
        return $this->state(fn (): array => [
            'tag' => 'v' . $this->faker->unique()->numerify('#.#.#') . '-beta.1',
            'kind' => ReleaseTagKind::Beta,
        ]);
    }
}
