<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\External;

use Illuminate\Support\Carbon;
use Modules\SAO\Attribution\CodeReference;
use Modules\SAO\Drivers\Contracts\CodeEventCapability;
use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConfigurationField;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Drivers\Support\DriverConfigurationSchema;
use Modules\SAO\Drivers\Support\HealthCheckResult;
use Modules\SAO\Drivers\Support\ReadsExternalPayloads;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\ChangeRefType;
use Modules\SAO\Enums\IngestMode;
use Override;

/**
 * The `code` driver for GitHub's `pull_request` webhook. Deliveries are
 * authenticated by GitHub's HMAC-SHA256 signature (`X-Hub-Signature-256:
 * sha256=<hex>`) over the raw body. Only a *merged* pull request produces a
 * {@see CodeReference} (a merged PR is the resolution evidence; opened/closed-
 * without-merge events unpack to nothing). The PR title and body are the text the
 * attribution writer scans for ticket keys. The merge commit sha is read only from
 * a merged PR: on an open one GitHub's `merge_commit_sha` is a test-merge commit no
 * environment contains.
 */
final readonly class GitHubPullRequestDriver implements CodeEventCapability, DriverInterface
{
    use ReadsExternalPayloads;

    private const string SIGNATURE_HEADER = 'x-hub-signature-256';

    #[Override]
    public function key(): string
    {
        return 'github-pull-request';
    }

    /**
     * @return list<Capability>
     */
    #[Override]
    public function capabilities(): array
    {
        return [Capability::Code];
    }

    /**
     * @return list<IngestMode>
     */
    #[Override]
    public function ingestModes(): array
    {
        return [IngestMode::Push];
    }

    #[Override]
    public function configurationSchema(): DriverConfigurationSchema
    {
        return new DriverConfigurationSchema([
            new ConfigurationField('secret', 'string', 'The GitHub webhook secret used to sign deliveries', required: true, secret: true),
        ]);
    }

    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        $secret = self::stringOr($context->credentials['secret'] ?? '');

        return $secret === ''
            ? HealthCheckResult::unhealthy('No webhook secret configured for the GitHub pull-request connection.')
            : HealthCheckResult::healthy();
    }

    /**
     * @param  array<string, string>  $headers
     */
    #[Override]
    public function verifySignature(BindingContext $context, string $payload, array $headers): bool
    {
        $secret = self::stringOr($context->connection->credentials['secret'] ?? '');

        if ($secret === '') {
            return false;
        }

        $provided = $this->signatureHeader($headers);

        if ($provided === null) {
            return false;
        }

        return hash_equals('sha256=' . hash_hmac('sha256', $payload, $secret), $provided);
    }

    /**
     * @return list<CodeReference>
     */
    #[Override]
    public function unpack(BindingContext $context, string $payload): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true) ?? [];

        /** @var array<string, mixed> $pr */
        $pr = is_array($decoded['pull_request'] ?? null) ? $decoded['pull_request'] : [];

        $merged = ($pr['merged'] ?? false) === true;
        $number = self::stringOrNull($pr['number'] ?? null);

        if (! $merged || $number === null) {
            return [];
        }

        $title = self::stringOrNull($pr['title'] ?? null) ?? '';
        $body = self::stringOrNull($pr['body'] ?? null) ?? '';

        return [new CodeReference(
            type: ChangeRefType::PullRequest,
            identifier: $number,
            text: mb_trim($title . "\n" . $body),
            url: self::stringOrNull($pr['html_url'] ?? null),
            source: $this->key(),
            mergedAt: $this->dateOrNull($pr['merged_at'] ?? null),
            baseRef: $this->ref($pr['base'] ?? null),
            headRef: $this->ref($pr['head'] ?? null),
            commitSha: self::stringOrNull($pr['merge_commit_sha'] ?? null),
        )];
    }

    private function ref(mixed $side): ?string
    {
        return is_array($side) ? self::stringOrNull($side['ref'] ?? null) : null;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function signatureHeader(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (mb_strtolower($name) === self::SIGNATURE_HEADER) {
                return $value;
            }
        }

        return null;
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
