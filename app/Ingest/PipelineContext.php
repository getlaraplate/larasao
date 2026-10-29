<?php

declare(strict_types=1);

namespace Modules\SAO\Ingest;

use Closure;

/**
 * Loop protection, layer 1 (spec §6.1). While any ingest/normalization/sync
 * work runs, this context is active; every log emitted in that window is stamped
 * with the pipeline-origin marker. The internal log source discards marked
 * records regardless of which module produced them, so an error raised *by* the
 * pipeline can never re-enter it. Bound as a singleton so the marker is shared
 * process-wide.
 */
final class PipelineContext
{
    public const string ORIGIN_KEY = 'pipeline_origin';

    private int $depth = 0;

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    public function enter(): void
    {
        $this->depth++;
    }

    public function leave(): void
    {
        $this->depth = max(0, $this->depth - 1);
    }

    /**
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Closure $callback): mixed
    {
        $this->enter();

        try {
            return $callback();
        } finally {
            $this->leave();
        }
    }

    /**
     * Stamp a log context with the origin marker while the pipeline is active.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function stamp(array $context): array
    {
        if ($this->isActive()) {
            $context[self::ORIGIN_KEY] = true;
        }

        return $context;
    }
}
