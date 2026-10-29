<?php

declare(strict_types=1);

namespace Modules\SAO\Models;

use Illuminate\Database\Eloquent\Casts\AsEnumCollection;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Overrides\Model;
use Modules\SAO\Database\Factories\ConnectionFactory;
use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ConnectionHealth;
use Modules\SAO\Enums\SAOTables;
use Modules\SAO\Exceptions\UnsupportedCapabilityException;
use Override;

/**
 * A configured instance of a driver.
 *
 * Holds only non-secret coordinates plus the secret via one of two paths (F4):
 * an encrypted, write-only `credential` payload, or a `credential_ref` env key
 * that overrides it. The raw secret is never rendered back to a UI. A
 * connection may expose only a subset of its driver's capabilities, enforced on
 * save.
 */
final class Connection extends Model
{
    /**
     * @var array<string, mixed>
     */
    #[Override]
    protected $attributes = [
        'health_state' => ConnectionHealth::Unknown->value,
    ];

    /**
     * @var list<string>
     */
    #[Override]
    protected $fillable = [
        'driver_key',
        'name',
        'base_url',
        'credential',
        'credential_ref',
        'config',
        'capabilities',
        'health_state',
        'last_checked_at',
    ];

    /**
     * @var string
     */
    #[Override]
    protected $table = SAOTables::Connections->value;

    /**
     * Resolve the registered driver this connection instantiates.
     */
    public function driver(DriverRegistry $registry): DriverInterface
    {
        return $registry->get($this->driver_key);
    }

    /**
     * Build the resolved, persistence-agnostic connection view for a driver.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function connectionContext(array $credentials): ConnectionContext
    {
        return new ConnectionContext($this->base_url, $credentials, $this->config ?? []);
    }

    /**
     * A connection may only expose capabilities its driver actually declares.
     */
    public function assertCapabilitiesSupported(DriverRegistry $registry): void
    {
        $supported = $this->driver($registry)->capabilities();

        foreach ($this->capabilities as $capability) {
            if (! in_array($capability, $supported, true)) {
                throw UnsupportedCapabilityException::for($this->driver_key, $capability);
            }
        }
    }

    /**
     * @return array<string, array<string, list<string>>>
     */
    #[Override]
    public function getRules(): array
    {
        $rules = parent::getRules();

        $table = SAOTables::Connections->value;

        $shared = [
            'driver_key' => ['string', 'max:255'],
            'name' => ['string', 'max:255'],
            'base_url' => ['nullable', 'string', 'max:2048'],
            'credential_ref' => ['nullable', 'string', 'max:255'],
            'config' => ['nullable', 'json'],
            // HasValidations validates the cast (serialized) value, which for an
            // AsEnumCollection column is a JSON string; the driver-subset
            // invariant enforces the semantic constraint on save.
            'capabilities' => ['json'],
            'health_state' => ['sometimes', 'string', ConnectionHealth::validationRule()],
        ];

        $rules['create'] = array_merge($rules['create'], $shared, [
            'driver_key' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255', "unique:{$table},name"],
            'capabilities' => ['required', 'json'],
        ]);

        $rules['update'] = array_merge($rules['update'], $shared);

        return $rules;
    }

    #[Override]
    protected static function booted(): void
    {
        parent::booted();

        self::saving(static function (Connection $connection): void {
            $connection->assertCapabilitiesSupported(app(DriverRegistry::class));
        });
    }

    /**
     * @return Factory<Connection>
     */
    protected static function newFactory(): Factory
    {
        return ConnectionFactory::new();
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'credential' => 'encrypted:array',
            'config' => 'array',
            'capabilities' => AsEnumCollection::of(Capability::class),
            'health_state' => ConnectionHealth::class,
            'last_checked_at' => 'datetime',
        ];
    }
}
