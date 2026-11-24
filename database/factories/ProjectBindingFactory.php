<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\SyncDirection;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\ProjectBinding;

/**
 * @extends Factory<ProjectBinding>
 */
final class ProjectBindingFactory extends Factory
{
    /**
     * @var class-string<ProjectBinding>
     */
    protected $model = ProjectBinding::class;

    /**
     * @return array<model-property<ProjectBinding>, mixed>
     */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'connection_id' => Connection::factory()->state(['driver_key' => 'fake', 'capabilities' => [Capability::Issues]]),
            'capability' => Capability::Issues,
            'remote_identifier' => (string) $this->faker->numberBetween(1, 9999),
            'sync_direction' => SyncDirection::Disabled,
            'status_map' => [],
            'priority_map' => [],
            'config' => [],
        ];
    }
}
