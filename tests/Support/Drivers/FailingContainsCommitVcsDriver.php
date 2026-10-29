<?php

declare(strict_types=1);

namespace Modules\SAO\Tests\Support\Drivers;

use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\Contracts\VcsCapability;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Drivers\Support\DriverConfigurationSchema;
use Modules\SAO\Drivers\Support\HealthCheckResult;
use Modules\SAO\Drivers\Support\Page;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Override;
use RuntimeException;

/**
 * A network-free `vcs` stub whose `containsCommit` throws for one sha and answers
 * `$answer` for every other, so a test can prove one failing pair does not abort
 * the rest of a containment batch.
 */
final readonly class FailingContainsCommitVcsDriver implements DriverInterface, VcsCapability
{
    public function __construct(
        private string $failingSha,
        private bool $answer = true,
        private string $key = 'failing-vcs',
    ) {}

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
        return [Capability::Vcs];
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
        return new Page([]);
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
        if ($sha === $this->failingSha) {
            throw new RuntimeException('The driver broke on this commit.');
        }

        return $this->answer;
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
}
