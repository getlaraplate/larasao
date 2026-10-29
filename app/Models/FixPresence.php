<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\FixPresenceFactory;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * Whether one environment, running one commit, contains one fix: a fixing
 * {@see ChangeRef}'s `commit_sha` against the environment's `current_commit` as
 * it was when asked (`deployed_commit`). The answer never changes for a given
 * commit, so it is computed once per (change ref, environment, deployed commit);
 * a new deployed commit adds a row and keeps the earlier ones.
 *
 * `contained` is null when the VCS could not tell; such a row is asked again on
 * the next check and never stands for "not contained".
 */
final class FixPresence extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'change_ref_id',
        'environment_id',
        'deployed_commit',
        'contained',
        'checked_at',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::FixPresences->value;

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'changeRef';
    }

    /**
     * @return BelongsTo<ChangeRef, $this>
     */
    public function changeRef(): BelongsTo
    {
        return $this->belongsTo(ChangeRef::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return Factory<FixPresence>
     */
    protected static function newFactory(): Factory
    {
        return FixPresenceFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'change_ref_id' => 'integer',
            'environment_id' => 'integer',
            'contained' => 'boolean',
            'checked_at' => 'datetime',
        ];
    }
}
