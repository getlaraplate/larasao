<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\StatusCategory;
use Modules\SAO\Models\TicketStatus;

/**
 * @extends Factory<TicketStatus>
 */
final class TicketStatusFactory extends Factory
{
    /**
     * @var class-string<TicketStatus>
     */
    protected $model = TicketStatus::class;

    /**
     * @return array<model-property<TicketStatus>, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->words(2, true),
            'category' => StatusCategory::Open,
            'colour' => 'gray',
        ];
    }

    public function category(StatusCategory $category): self
    {
        return $this->state(fn (): array => ['category' => $category]);
    }
}
