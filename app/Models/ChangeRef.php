<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ChangeRefFactory;
use Modules\SAO\Enums\ChangeRefRelation;
use Modules\SAO\Enums\ChangeRefType;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * The link between a code artefact — a commit, pull request or tag — and a
 * ticket, recording the source that produced it. It is the raw material of
 * code-to-work correlation (commit → ticket) built in phase 6.
 */
final class ChangeRef extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'ticket_id',
        'type',
        'relation',
        'identifier',
        'url',
        'source',
        'merged_at',
        'base_ref',
        'head_ref',
        'commit_sha',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::ChangeRefs->value;

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'ticket';
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Whether this reference is a merged pull request.
     */
    public function isMergedPullRequest(): bool
    {
        return $this->type === ChangeRefType::PullRequest && $this->merged_at !== null;
    }

    /**
     * @return Factory<ChangeRef>
     */
    protected static function newFactory(): Factory
    {
        return ChangeRefFactory::new();
    }

    /**
     * Merged pull-request references only.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function mergedPullRequests(Builder $query): Builder
    {
        return $query
            ->where('type', ChangeRefType::PullRequest->value)
            ->whereNotNull('merged_at');
    }

    /**
     * References that claim to resolve the ticket — the only ones that count as
     * fix evidence. Mentions are excluded.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    #[Scope]
    protected function fixes(Builder $query): Builder
    {
        return $query->where('relation', ChangeRefRelation::Fixes->value);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'type' => ChangeRefType::class,
            'relation' => ChangeRefRelation::class,
            'merged_at' => 'datetime',
        ];
    }
}
