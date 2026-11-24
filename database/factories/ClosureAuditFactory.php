<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\ClosureAction;
use Modules\SAO\Models\ClosureAudit;
use Modules\SAO\Models\Ticket;

/**
 * @extends Factory<ClosureAudit>
 */
final class ClosureAuditFactory extends Factory
{
    /**
     * @var class-string<ClosureAudit>
     */
    protected $model = ClosureAudit::class;

    /**
     * @return array<model-property<ClosureAudit>, mixed>
     */
    public function definition(): array
    {
        return [
            'ticket_id' => Ticket::factory(),
            'closure_policy_id' => null,
            'action' => ClosureAction::Close,
            'conditions_held' => [
                'fix_deployed_there' => ['held' => true, 'evidence' => ['fix_deployed_there' => true]],
            ],
            'reporting_environment' => 'production',
            'closed_at' => now(),
            'is_premature' => false,
        ];
    }
}
