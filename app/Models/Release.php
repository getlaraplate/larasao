<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ReleaseFactory;
use Modules\SAO\Enums\ReleaseStatus;
use Modules\SAO\Enums\ReleaseTagKind;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * A product version of a project, named as its stable label. It gathers the
 * concrete VCS tags that realize it and the tickets attributed to it, so SAO
 * can answer "which release carries this fix" from data rather than guesses.
 */
final class Release extends Model
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'status' => ReleaseStatus::Announced->value,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'project_id',
        'version',
        'status',
        'released_at',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::Releases->value;

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<ReleaseTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(ReleaseTag::class);
    }

    /**
     * @return HasMany<TicketRelease, $this>
     */
    public function ticketReleases(): HasMany
    {
        return $this->hasMany(TicketRelease::class);
    }

    /**
     * The highest maturity among the release's tags, or `null` when it carries
     * no tag. A tagless release (typically an `observed` one) has unknown
     * maturity and can never be a resolution target.
     */
    public function effectiveMaturity(): ?ReleaseTagKind
    {
        return $this->tags
            ->sortByDesc(static fn (ReleaseTag $tag): int => $tag->kind->precedence())
            ->first()?->kind;
    }

    /**
     * @return Factory<Release>
     */
    protected static function newFactory(): Factory
    {
        return ReleaseFactory::new();
    }

    /**
     * Curated releases only — those a maintainer has adopted. Excludes the
     * `observed` versions auto-recorded from logs or entered by a reporter,
     * which are hidden from version lists by default.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function curated(Builder $query): Builder
    {
        return $query->where('status', '!=', ReleaseStatus::Observed->value);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
            'status' => ReleaseStatus::class,
            'released_at' => 'datetime',
        ];
    }
}
