<?php

declare(strict_types=1);

namespace Modules\SAO\Attribution;

use Modules\SAO\Drivers\Contracts\ReleasesCapability;
use Modules\SAO\Drivers\Contracts\VcsCapability;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionCredentialResolver;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ChangeRefRelation;
use Modules\SAO\Enums\ChangeRefType;
use Modules\SAO\Models\ChangeRef;
use Modules\SAO\Models\ProjectBinding;

/**
 * The pull transport for code-to-work attribution: it walks a `vcs` binding's
 * commits, extracts ticket references from each message through
 * {@see CodeReferenceWriter}, and — when the same driver also exposes `releases` —
 * attributes each fixing commit to the release that carries it via
 * {@see ReleaseAttributionService}.
 *
 * Idempotent (the writer upserts), so re-scanning a branch is safe and
 * backfillable. Page walking is bounded like the issue poller.
 */
final readonly class VcsScanService
{
    private const int MAX_PAGES = 1000;

    public function __construct(
        private DriverRegistry $registry,
        private ConnectionCredentialResolver $resolver,
        private CodeReferenceWriter $writer,
        private ReleaseAttributionService $releaseAttribution,
    ) {}

    public function scan(ProjectBinding $binding, string $range): VcsScanReport
    {
        if ($binding->capability !== Capability::Vcs) {
            return VcsScanReport::skipped();
        }

        $driver = $this->registry->get($binding->remoteConnection->driver_key);

        if (! $driver instanceof VcsCapability) {
            return VcsScanReport::skipped();
        }

        $context = $binding->bindingContext($this->resolver);
        $releases = $driver instanceof ReleasesCapability ? $driver : null;

        $commitsScanned = 0;
        $fixLinks = 0;
        $mentionLinks = 0;
        $releasesAttributed = 0;
        $unknownKeys = [];
        $pages = 0;
        $truncated = false;
        $cursor = null;

        do {
            if ($pages >= self::MAX_PAGES) {
                $truncated = true;

                break;
            }

            $page = $driver->commits($context, $range, $cursor);
            $pages++;

            foreach ($page->items as $commit) {
                $sha = (string) ($commit['sha'] ?? '');

                if ($sha === '') {
                    continue;
                }

                $commitsScanned++;

                $outcome = $this->writer->write(new CodeReference(
                    type: ChangeRefType::Commit,
                    identifier: $sha,
                    text: (string) ($commit['message'] ?? ''),
                    url: isset($commit['url']) ? (string) $commit['url'] : null,
                    source: $driver->key(),
                    commitSha: $sha,
                ));

                $unknownKeys = [...$unknownKeys, ...$outcome->unknownKeys];

                foreach ($outcome->changeRefs as $changeRef) {
                    if ($changeRef->relation === ChangeRefRelation::Fixes) {
                        $fixLinks++;
                        $releasesAttributed += $this->attributeRelease($changeRef, $sha, $releases, $context);
                    } else {
                        $mentionLinks++;
                    }
                }
            }

            $cursor = $page->nextCursor;
        } while ($cursor !== null);

        return new VcsScanReport(
            processed: true,
            commitsScanned: $commitsScanned,
            fixLinks: $fixLinks,
            mentionLinks: $mentionLinks,
            releasesAttributed: $releasesAttributed,
            unknownKeys: array_values(array_unique($unknownKeys)),
            pages: $pages,
            truncated: $truncated,
        );
    }

    private function attributeRelease(ChangeRef $changeRef, string $sha, ?ReleasesCapability $releases, BindingContext $context): int
    {
        if ($releases === null || $changeRef->ticket === null) {
            return 0;
        }

        $ticketRelease = $this->releaseAttribution->attribute($changeRef->ticket, $sha, $releases, $context);

        return $ticketRelease !== null ? 1 : 0;
    }
}
