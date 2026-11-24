<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\SignalAliasFactory;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * A superseded `group_key` pointing at its {@see Signal}. The mechanism that
 * lets the fingerprint algorithm evolve without splitting history: when a key
 * format changes, the old key is aliased to the signal it used to open.
 *
 * @property int $id
 * @property int $signal_id
 * @property string $group_key A superseded group key that now points at this signal
 * @property int $algo_version
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class SignalAlias extends Model implements IsPartOfParent
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'algo_version' => 1,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'signal_id',
        'group_key',
        'algo_version',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::SignalAliases->value;

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'signal';
    }

    /**
     * @return BelongsTo<Signal, $this>
     */
    public function signal(): BelongsTo
    {
        return $this->belongsTo(Signal::class);
    }

    /**
     * @return Factory<SignalAlias>
     */
    protected static function newFactory(): Factory
    {
        return SignalAliasFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'signal_id' => 'integer',
            'algo_version' => 'integer',
        ];
    }
}
