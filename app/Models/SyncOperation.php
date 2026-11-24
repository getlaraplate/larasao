<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\SyncOutcome;
use Override;

/**
 * The idempotency ledger: one row per completed synchronization, keyed so a
 * retry with the same binding, ticket and content is recognised and skipped
 * rather than producing a second remote write.
 *
 * @property int $id
 * @property int $binding_id
 * @property string $idempotency_key Stable key over binding, ticket and content so a retry is a no-op
 * @property SyncOutcome $outcome
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class SyncOperation extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'binding_id',
        'idempotency_key',
        'outcome',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::SyncOperations->value;

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'binding';
    }

    /**
     * @return BelongsTo<ProjectBinding, $this>
     */
    public function binding(): BelongsTo
    {
        return $this->belongsTo(ProjectBinding::class, 'binding_id');
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $rules['create'] = array_merge($rules['create'], [
            'binding_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:255'],
            'outcome' => ['required', 'string', 'in:' . implode(',', SyncOutcome::values())],
        ]);

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'outcome' => SyncOutcome::class,
        ];
    }
}
