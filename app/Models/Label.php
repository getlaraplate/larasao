<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\LabelFactory;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Models\Pivot\TicketLabel;
use Override;

/**
 * A label is project-scoped: the same name may exist in different projects but
 * is unique within one.
 *
 * @property int $id
 * @property int $project_id
 * @property string $name
 * @property string $colour
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class Label extends Model implements IsPartOfParent
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'colour' => 'gray',
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'project_id',
        'name',
        'colour',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::Labels->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $rules['create'] = array_merge($rules['create'], [
            'project_id' => ['required', 'integer', 'exists:' . SAOTables::Projects->value . ',id'],
            'name' => ['required', 'string', 'max:255'],
            'colour' => ['sometimes', 'string', 'max:16'],
        ]);

        $rules['update'] = array_merge($rules['update'], [
            'name' => ['sometimes', 'string', 'max:255'],
            'colour' => ['sometimes', 'string', 'max:16'],
        ]);

        return $rules;
    }

    /**
     * The relation to the record this one only exists inside, whose visibility it inherits.
     */
    #[Override]
    public function parentRelation(): string
    {
        return 'project';
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsToMany<Ticket, $this>
     */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, SAOTables::TicketLabel->value)
            ->using(TicketLabel::class)
            ->withTimestamps();
    }

    /**
     * @return Factory<Label>
     */
    protected static function newFactory(): Factory
    {
        return LabelFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'project_id' => 'integer',
        ];
    }
}
