<?php

declare(strict_types=1);

namespace Modules\SAO\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\Environment;
use Modules\SAO\Services\FixPresenceService;

/**
 * Checks the fix presences one change affects: every fix of a project against an
 * environment whose `current_commit` changed ({@see self::forEnvironment()}), or one
 * fixing change ref against every environment of its project
 * ({@see self::forChangeRef()}). It carries an id, not the model, so a record
 * deleted before the job runs makes it a no-op instead of a failure.
 *
 * Dispatched after commit by the `Environment` and `ChangeRef` saved hooks.
 * Rerunning it is safe: a known answer is not asked again.
 */
final class CheckFixPresenceJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    private function __construct(
        public readonly ?int $environmentId,
        public readonly ?int $changeRefId,
    ) {}

    public static function forEnvironment(int $id): self
    {
        return new self(environmentId: $id, changeRefId: null);
    }

    public static function forChangeRef(int $id): self
    {
        return new self(environmentId: null, changeRefId: $id);
    }

    public function handle(FixPresenceService $service): void
    {
        if ($this->environmentId !== null) {
            $environment = Environment::query()->find($this->environmentId);

            if ($environment instanceof Environment) {
                $service->checkEnvironment($environment);
            }

            return;
        }

        if ($this->changeRefId !== null) {
            $changeRef = ChangeRef::query()->find($this->changeRefId);

            if ($changeRef instanceof ChangeRef) {
                $service->checkChangeRef($changeRef);
            }
        }
    }
}
