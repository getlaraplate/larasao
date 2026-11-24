<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\SAO\Enums\ImportRunStatus;
use Modules\SAO\Enums\ImportScope;
use Modules\SAO\Models\ImportRun;
use Modules\SAO\Models\ProjectBinding;

/**
 * @extends Factory<ImportRun>
 */
final class ImportRunFactory extends Factory
{
    /**
     * @var class-string<ImportRun>
     */
    protected $model = ImportRun::class;

    /**
     * @return array<model-property<ImportRun>, mixed>
     */
    public function definition(): array
    {
        return [
            'binding_id' => ProjectBinding::factory(),
            'scope' => ImportScope::All,
            'status' => ImportRunStatus::Running,
            'cursor' => null,
            'created_count' => 0,
            'updated_count' => 0,
            'filtered_count' => 0,
            'skipped_count' => 0,
            'pages' => 0,
            'truncated' => false,
        ];
    }

    public function completed(): self
    {
        return $this->state(fn (): array => [
            'status' => ImportRunStatus::Completed,
            'cursor' => null,
        ]);
    }
}
