<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Modules\SAO\Data\ChangeContext;
use Modules\SAO\Database\Factories\TicketCommentFactory;
use Modules\SAO\Enums\CommentOrigin;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Exceptions\ImmutableSystemCommentException;
use Override;

/**
 * @property int $id
 * @property int $ticket_id
 * @property int|null $author_id Null for system comments
 * @property CommentOrigin $origin
 * @property string|null $source_key Which automation wrote it, for system comments
 * @property string $body
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class TicketComment extends Model implements IsPartOfParent
{
    /**
     * Mirrors the migration default so a new instance reports what it will hold
     * once persisted.
     *
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'origin' => CommentOrigin::Human->value,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'ticket_id',
        'author_id',
        'origin',
        'source_key',
        'body',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::TicketComments->value;

    /**
     * The single creation path: origin and authorship are derived from the
     * context, never passed in by a caller.
     */
    public static function postFor(Ticket $ticket, string $body, ChangeContext $context): self
    {
        $source_key = $context->sourceKey();

        /** @var self */
        return self::query()->create([
            'ticket_id' => $ticket->getKey(),
            'author_id' => $context->userId(),
            'origin' => $source_key === null ? CommentOrigin::Human : CommentOrigin::System,
            'source_key' => $source_key,
            'body' => $body,
        ]);
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $rules['create'] = array_merge($rules['create'], [
            'ticket_id' => ['required', 'integer', 'exists:' . SAOTables::Tickets->value . ',id'],
            'author_id' => ['nullable', 'integer'],
            'origin' => ['sometimes', 'string', CommentOrigin::validationRule()],
            'source_key' => ['nullable', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'body' => ['sometimes', 'string'],
        ]);

        return $rules;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

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

    protected static function booted(): void
    {
        self::updating(static function (self $comment): void {
            if ($comment->origin === CommentOrigin::System) {
                throw ImmutableSystemCommentException::make();
            }
        });
    }

    /**
     * @return Factory<TicketComment>
     */
    protected static function newFactory(): Factory
    {
        return TicketCommentFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'author_id' => 'integer',
            'origin' => CommentOrigin::class,
        ];
    }
}
