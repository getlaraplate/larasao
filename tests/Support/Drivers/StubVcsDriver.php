<?php

declare(strict_types=1);

namespace Modules\SAO\Tests\Support\Drivers;

use ArrayObject;
use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\Contracts\ReleasesCapability;
use Modules\SAO\Drivers\Contracts\VcsCapability;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Drivers\Support\DriverConfigurationSchema;
use Modules\SAO\Drivers\Support\HealthCheckResult;
use Modules\SAO\Drivers\Support\Page;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Override;

/**
 * A network-free `vcs` + `releases` stub: `commits` returns a fixed list set at
 * construction and `firstTagContaining` a fixed tag, so the pull-scan attribution
 * pipeline can be exercised offline. Register it in the {@see \Modules\SAO\Drivers\DriverRegistry}
 * and point a connection's `driver_key` at {@see key()}.
 *
 * `containsCommit` answers the tri-state set at construction (true, false, or null for
 * "cannot tell") and records each call, so a test can assert how many VCS calls were
 * made and with which arguments.
 */
final readonly class StubVcsDriver implements DriverInterface, ReleasesCapability, VcsCapability
{
    /**
     * @var ArrayObject<int, array{sha: string, ref: string}>
     */
    private ArrayObject $containsCommitCalls;

    /**
     * @param  list<array<string, mixed>>  $commits
     * @param  list<string>  $tags  tags returned by tags(); defaults to just $tag
     * @param  bool|null  $containsCommit  the answer of containsCommit(); null means "cannot tell"
     */
    public function __construct(
        private array $commits = [],
        private ?string $tag = null,
        private string $key = 'stub-vcs',
        private array $tags = [],
        private ?bool $containsCommit = null,
    ) {
        $this->containsCommitCalls = new ArrayObject;
    }

    /**
     * How many times containsCommit() was called.
     */
    public function containsCommitCallCount(): int
    {
        return $this->containsCommitCalls->count();
    }

    /**
     * The arguments of every containsCommit() call, in order.
     *
     * @return list<array{sha: string, ref: string}>
     */
    public function containsCommitCalls(): array
    {
        return array_values($this->containsCommitCalls->getArrayCopy());
    }

    #[Override]
    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return list<Capability>
     */
    #[Override]
    public function capabilities(): array
    {
        return [Capability::Vcs, Capability::Releases];
    }

    /**
     * @return list<IngestMode>
     */
    #[Override]
    public function ingestModes(): array
    {
        return [IngestMode::Pull];
    }

    #[Override]
    public function configurationSchema(): DriverConfigurationSchema
    {
        return new DriverConfigurationSchema([]);
    }

    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        return HealthCheckResult::healthy();
    }

    #[Override]
    public function commits(BindingContext $context, string $range, ?string $cursor = null): Page
    {
        return new Page($this->commits);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function compare(BindingContext $context, string $base, string $head): array
    {
        return [];
    }

    #[Override]
    public function containsCommit(BindingContext $context, string $sha, string $ref): ?bool
    {
        $this->containsCommitCalls[] = ['sha' => $sha, 'ref' => $ref];

        return $this->containsCommit;
    }

    #[Override]
    public function fileAtRef(BindingContext $context, string $path, string $ref): ?string
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    #[Override]
    public function openPullRequest(BindingContext $context, array $attributes): array
    {
        return [];
    }

    #[Override]
    public function tags(BindingContext $context, ?string $cursor = null): Page
    {
        if ($this->tags !== []) {
            return new Page(array_map(static fn (string $tag): array => ['tag' => $tag], $this->tags));
        }

        return new Page($this->tag === null ? [] : [['tag' => $this->tag]]);
    }

    #[Override]
    public function firstTagContaining(BindingContext $context, string $commitSha): ?string
    {
        return $this->tag;
    }
}
