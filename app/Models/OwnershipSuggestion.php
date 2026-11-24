<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\OwnershipSuggestionFactory;
use Modules\SAO\Enums\OwnershipRule;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * A deterministic, code-evidence-based proposal of who should own a ticket. It
 * is a suggestion and only that: SAO may propose ownership but never applies an
 * assignee automatically (D14). The evidence records the paths and rule-specific
 * data behind the choice so a human can judge it.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int|null $suggested_user_id
 * @property OwnershipRule $rule
 * @property float $score
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 * @property array<array-key, mixed> $evidence The paths/commits and rule-specific data behind the suggestion
 */
final class OwnershipSuggestion extends Model
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'ticket_id',
        'suggested_user_id',
        'rule',
        'score',
        'evidence',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::OwnershipSuggestions->value;

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function suggestedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_user_id');
    }

    /**
     * @return Factory<OwnershipSuggestion>
     */
    protected static function newFactory(): Factory
    {
        return OwnershipSuggestionFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'suggested_user_id' => 'integer',
            'rule' => OwnershipRule::class,
            'score' => 'float',
            'evidence' => 'array',
        ];
    }
}
