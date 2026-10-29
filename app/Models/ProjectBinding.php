<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Contracts\IsPartOfParent;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ProjectBindingFactory;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Enums\SyncDirection;
use Modules\SAO\Exceptions\MissingCredentialException;
use Modules\SAO\Exceptions\UnsupportedCapabilityException;
use Override;

/**
 * Binds a project to one capability of one connection, with the remote object
 * it targets and the binding-scoped configuration (sync direction, status and
 * priority maps). Multiple bindings of the same family are allowed.
 */
final class ProjectBinding extends Model implements IsPartOfParent
{
    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'project_id',
        'connection_id',
        'capability',
        'remote_identifier',
        'sync_direction',
        'status_map',
        'priority_map',
        'config',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::ProjectBindings->value;

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
     * Named `remoteConnection` rather than `connection` to avoid colliding with
     * Eloquent's built-in `$connection` (database connection name) property.
     *
     * @return BelongsTo<Connection, $this>
     */
    public function remoteConnection(): BelongsTo
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * Build the resolved view a capability call operates on: the connection
     * (base URL + resolved credentials) plus this binding's remote identifier,
     * config and maps.
     *
     * With `$credentialOptional`, a connection that holds no secret at all
     * resolves to an empty credential set instead of failing, for a driver whose
     * secret is optional (an unauthenticated probe). A connection that names a
     * `credential_ref` is still required to resolve it.
     */
    public function bindingContext(ConnectionCredentialResolver $resolver, bool $credentialOptional = false): BindingContext
    {
        $connection = $this->remoteConnection;

        try {
            $credentials = $resolver->resolve($connection);
        } catch (MissingCredentialException $exception) {
            $ref = $connection->credential_ref;

            if (! $credentialOptional || ($ref !== null && $ref !== '')) {
                throw $exception;
            }

            $credentials = [];
        }

        return new BindingContext(
            $connection->connectionContext($credentials),
            remoteIdentifier: $this->remote_identifier,
            config: $this->config ?? [],
            statusMap: $this->status_map ?? [],
            priorityMap: $this->priority_map ?? [],
        );
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $shared = [
            'remote_identifier' => ['nullable', 'string', 'max:255'],
            'sync_direction' => ['sometimes', 'string', SyncDirection::validationRule()],
            'status_map' => ['nullable', 'json'],
            'priority_map' => ['nullable', 'json'],
            'config' => ['nullable', 'json'],
        ];

        $rules['create'] = array_merge($rules['create'], $shared, [
            'project_id' => ['required', 'integer', 'exists:' . SAOTables::Projects->value . ',id'],
            'connection_id' => ['required', 'integer', 'exists:' . SAOTables::Connections->value . ',id'],
            'capability' => ['required', 'string', Capability::validationRule()],
        ]);

        $rules['update'] = array_merge($rules['update'], $shared);

        return $rules;
    }

    #[Override]
    protected static function booted(): void
    {
        parent::booted();

        self::saving(static function (ProjectBinding $binding): void {
            $connection = $binding->remoteConnection;

            if (! in_array($binding->capability, $connection->capabilities->all(), true)) {
                throw UnsupportedCapabilityException::for($connection->driver_key, $binding->capability);
            }
        });
    }

    /**
     * @return Factory<ProjectBinding>
     */
    protected static function newFactory(): Factory
    {
        return ProjectBindingFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'capability' => Capability::class,
            'sync_direction' => SyncDirection::class,
            'status_map' => 'array',
            'priority_map' => 'array',
            'config' => 'array',
        ];
    }
}
