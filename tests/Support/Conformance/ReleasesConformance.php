<?php

declare(strict_types=1);

namespace Modules\SAO\Tests\Support\Conformance;

use Modules\SAO\Drivers\Contracts\ReleasesCapability;
use Modules\SAO\Drivers\Support\BindingContext;

/**
 * The battery every `releases` driver must pass (spec §12).
 */
final class ReleasesConformance
{
    public static function assert(ReleasesCapability $driver, BindingContext $context): void
    {
        $tags = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $driver->tags($context, $cursor);

            foreach ($page->items as $item) {
                $tags[] = $item['tag'] ?? null;
            }

            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 100);

        expect($pages)->toBeGreaterThan(1)
            ->and($tags)->not->toContain(null)
            ->and($driver->firstTagContaining($context, 'abc1234'))->toBeString();
    }
}
