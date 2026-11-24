<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Contracts\IActivatableModel;
use Modules\Core\Models\Concerns\HasActivation;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\SourceProfileFactory;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * A stored normalization profile: the matchers that decide which payloads it
 * applies to, and the field bindings (canonical field => payload dot-path) that
 * turn a payload into canonical fields. Supporting a new source is adding a row,
 * not writing code.
 *
 * @property int $id
 * @property string $name
 * @property bool $is_active
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class SourceProfile extends Model implements IActivatableModel
{
    use HasActivation;

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'name',
        'is_active',
        'matchers',
        'field_bindings',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::SourceProfiles->value;

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $rules['create'] = array_merge($rules['create'], [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'matchers' => ['required', 'array'],
            'field_bindings' => ['required', 'array'],
        ]);

        return $rules;
    }

    /**
     * @return Factory<SourceProfile>
     */
    protected static function newFactory(): Factory
    {
        return SourceProfileFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'matchers' => 'array',
            'field_bindings' => 'array',
        ];
    }
}
