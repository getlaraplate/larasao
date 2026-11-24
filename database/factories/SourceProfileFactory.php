<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Models\SourceProfile;

/**
 * @extends Factory<SourceProfile>
 */
final class SourceProfileFactory extends Factory
{
    /**
     * @var class-string<SourceProfile>
     */
    protected $model = SourceProfile::class;

    /**
     * @return array<model-property<SourceProfile>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'is_active' => true,
            'matchers' => [
                ['path' => 'source', 'operator' => 'equals', 'value' => 'demo'],
            ],
            'field_bindings' => [
                'message' => 'error.message',
                'class' => 'error.type',
                'project_key' => 'project.slug',
            ],
        ];
    }
}
