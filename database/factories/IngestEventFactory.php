<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\IngestPipeline;
use Modules\SAO\Enums\IngestStatus;
use Modules\SAO\Models\IngestEvent;

/**
 * @extends Factory<IngestEvent>
 */
final class IngestEventFactory extends Factory
{
    /**
     * @var class-string<IngestEvent>
     */
    protected $model = IngestEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'connection_id' => null,
            'pipeline' => IngestPipeline::Profile,
            'delivery_id' => $this->faker->unique()->uuid(),
            'payload' => ['error' => ['message' => 'boom']],
            'status' => IngestStatus::Received,
            'outcome' => null,
        ];
    }
}
