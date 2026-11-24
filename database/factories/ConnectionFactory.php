<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ConnectionHealth;
use Modules\SAO\Models\Connection;

/**
 * @extends Factory<Connection>
 */
final class ConnectionFactory extends Factory
{
    /**
     * @var class-string<Connection>
     */
    protected $model = Connection::class;

    /**
     * @return array<model-property<Connection>, mixed>
     */
    public function definition(): array
    {
        return [
            'driver_key' => 'fake',
            'name' => $this->faker->unique()->words(2, true),
            'base_url' => $this->faker->url(),
            'credential' => null,
            'credential_ref' => null,
            'capabilities' => [Capability::Issues],
            'health_state' => ConnectionHealth::Unknown,
        ];
    }

    public function health(ConnectionHealth $state): self
    {
        return $this->state(fn (): array => ['health_state' => $state]);
    }
}
