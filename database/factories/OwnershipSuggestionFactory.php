<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\User;
use Modules\SAO\Enums\OwnershipRule;
use Modules\SAO\Models\OwnershipSuggestion;
use Modules\SAO\Models\Ticket;

/**
 * @extends Factory<OwnershipSuggestion>
 */
final class OwnershipSuggestionFactory extends Factory
{
    /**
     * @var class-string<OwnershipSuggestion>
     */
    protected $model = OwnershipSuggestion::class;

    /**
     * @return array<model-property<OwnershipSuggestion>, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'suggested_user_id' => User::factory(),
            'rule' => OwnershipRule::Codeowners,
            'score' => 1.0,
            'evidence' => ['paths' => ['app/Example.php']],
        ];
    }
}
