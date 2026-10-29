<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\MatchType;
use Modules\SAO\Models\CorrelationRule;
use Modules\SAO\Models\Project;

/**
 * @extends Factory<CorrelationRule>
 */
final class CorrelationRuleFactory extends Factory
{
    /**
     * @var class-string<CorrelationRule>
     */
    protected $model = CorrelationRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'priority' => 100,
            'is_active' => true,
            'target' => CorrelationTarget::ServerHost,
            'match' => MatchType::Exact,
            'pattern' => $this->faker->unique()->domainName(),
            'project_id' => Project::factory(),
        ];
    }
}
