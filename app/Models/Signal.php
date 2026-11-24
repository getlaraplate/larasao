<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\SignalFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\SignalState;
use Override;

/**
 * A grouped, counted error scoped to a project. The `group_key` is comparable
 * across projects (the same bug in two projects yields two signals with the same
 * key, one per project — never auto-merged). `algo_version` records which
 * fingerprint algorithm produced the key, so the algorithm can evolve later.
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $ticket_id
 * @property string $group_key
 * @property int $algo_version
 * @property SignalState $state
 * @property int $occurrence_count
 * @property \Carbon\CarbonImmutable|null $first_seen_at
 * @property \Carbon\CarbonImmutable|null $last_seen_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class Signal extends Model
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'algo_version' => 1,
        'state' => SignalState::Open->value,
        'occurrence_count' => 0,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'project_id',
        'ticket_id',
        'group_key',
        'algo_version',
        'state',
        'occurrence_count',
        'first_seen_at',
        'last_seen_at',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::Signals->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $rules['create'] = array_merge($rules['create'], [
            'project_id' => ['required', 'integer', 'exists:' . SAOTables::Projects->value . ',id'],
            'group_key' => ['required', 'string', 'max:255'],
            'algo_version' => ['sometimes', 'integer', 'min:1'],
            'state' => ['sometimes', 'string', SignalState::validationRule()],
        ]);

        return $rules;
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * The ticket this signal was promoted to or correlated with, if any.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * @return HasMany<SignalOccurrence, $this>
     */
    public function occurrences(): HasMany
    {
        return $this->hasMany(SignalOccurrence::class);
    }

    /**
     * @return HasMany<SignalAlias, $this>
     */
    public function aliases(): HasMany
    {
        return $this->hasMany(SignalAlias::class);
    }

    /**
     * @return Factory<Signal>
     */
    protected static function newFactory(): Factory
    {
        return SignalFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'ticket_id' => 'integer',
            'algo_version' => 'integer',
            'state' => SignalState::class,
            'occurrence_count' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }
}
