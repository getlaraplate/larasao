<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ReleaseTagFactory;
use Modules\SAO\Enums\ReleaseTagKind;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * A concrete VCS tag realizing a {@see Release}. A `stable` tag makes the
 * release shippable; a `candidate` keeps a testable reference for staging.
 *
 * @property int $id
 * @property int $release_id
 * @property string $tag The concrete VCS tag, e.g. v1.4.0 or v1.4.0-rc.1
 * @property ReleaseTagKind $kind
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class ReleaseTag extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'release_id',
        'tag',
        'kind',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::ReleaseTags->value;

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'release';
    }

    /**
     * @return BelongsTo<Release, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class);
    }

    /**
     * @return Factory<ReleaseTag>
     */
    protected static function newFactory(): Factory
    {
        return ReleaseTagFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'release_id' => 'integer',
            'kind' => ReleaseTagKind::class,
        ];
    }
}
