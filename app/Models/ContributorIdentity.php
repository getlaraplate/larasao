<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\User;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ContributorIdentityFactory;
use Modules\SAO\Enums\SAOTables;
use Override;

/**
 * Ties a VCS identity — an account handle or a git author email — to a Core
 * user, per provider. It is the directory that lets the ownership resolvers
 * turn a commit or CODEOWNERS entry into a real user; an empty `provider` means
 * the mapping applies to any provider.
 *
 * @property int $id
 * @property int $user_id
 * @property string $provider Driver key (github/gitlab/…); '' means any provider
 * @property string $identity A VCS handle (octocat) or a git author email
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property \Carbon\CarbonImmutable|null $deleted_at
 * @property bool|null $is_deleted Whether the entity is deleted
 */
final class ContributorIdentity extends Model
{
    public const string ANY_PROVIDER = '';

    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'provider' => self::ANY_PROVIDER,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'user_id',
        'provider',
        'identity',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::ContributorIdentities->value;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Factory<ContributorIdentity>
     */
    protected static function newFactory(): Factory
    {
        return ContributorIdentityFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }
}
