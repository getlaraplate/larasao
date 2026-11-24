<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\User;
use Modules\SAO\Enums\TicketPriority;
use Modules\SAO\Models\SavedFilter;
use Modules\SAO\Services\TicketSearchCriteria;

/**
 * @extends Factory<SavedFilter>
 */
final class SavedFilterFactory extends Factory
{
    /**
     * @var class-string<SavedFilter>
     */
    protected $model = SavedFilter::class;

    /**
     * @return array<model-property<SavedFilter>, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'project_id' => null,
            'name' => $this->faker->unique()->words(2, true),
            'criteria' => (new TicketSearchCriteria(priority: TicketPriority::High))->toArray(),
        ];
    }
}
