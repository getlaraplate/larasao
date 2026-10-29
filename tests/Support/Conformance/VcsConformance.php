<?php

declare(strict_types=1);

namespace Modules\SAO\Tests\Support\Conformance;

use Modules\SAO\Drivers\Contracts\VcsCapability;
use Modules\SAO\Drivers\Support\BindingContext;

/**
 * The battery every `vcs` driver must pass (spec §12): commits paginate and
 * carry a sha, a comparison returns a payload, a file reads at a ref (and a
 * missing one is null), a pull/merge request can be opened, and a ref reports
 * whether it contains a commit.
 */
final class VcsConformance
{
    public static function assert(VcsCapability $driver, BindingContext $context): void
    {
        self::assertCommitsPaginate($driver, $context);
        self::assertCompareReturnsPayload($driver, $context);
        self::assertFileAtRef($driver, $context);
        self::assertOpensPullRequest($driver, $context);
        self::assertContainsCommit($driver, $context);
    }

    /**
     * A commit the ref contains answers true; the answer is a tri-state, never a
     * throw, so a caller can tell "no" (false) from "cannot tell" (null).
     */
    public static function assertContainsCommit(VcsCapability $driver, BindingContext $context): void
    {
        expect($driver->containsCommit($context, 'abc1234', 'main'))->toBeTrue();
    }

    private static function assertCommitsPaginate(VcsCapability $driver, BindingContext $context): void
    {
        $shas = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $driver->commits($context, 'main', $cursor);

            foreach ($page->items as $item) {
                $shas[] = $item['sha'] ?? null;

                // Every commit carries the normalized author shape, even when a
                // field is null (an unlinked account, a host with no username).
                expect($item)->toHaveKeys(['author', 'author_name', 'author_email']);
            }

            $cursor = $page->nextCursor;
            $pages++;
        } while ($cursor !== null && $pages < 100);

        expect($pages)->toBeGreaterThan(1)
            ->and($shas)->not->toContain(null);
    }

    private static function assertCompareReturnsPayload(VcsCapability $driver, BindingContext $context): void
    {
        expect($driver->compare($context, 'v1.0.0', 'main'))->toBeArray();
    }

    private static function assertFileAtRef(VcsCapability $driver, BindingContext $context): void
    {
        expect($driver->fileAtRef($context, 'README.md', 'main'))->toBeString()
            ->and($driver->fileAtRef($context, 'does/not/exist.txt', 'main'))->toBeNull();
    }

    private static function assertOpensPullRequest(VcsCapability $driver, BindingContext $context): void
    {
        $pr = $driver->openPullRequest($context, [
            'title' => 'Conformance PR',
            'source' => 'feature/x',
            'target' => 'main',
        ]);

        expect($pr)->toBeArray()
            ->and(isset($pr['remote_id']) || isset($pr['url']))->toBeTrue();
    }
}
