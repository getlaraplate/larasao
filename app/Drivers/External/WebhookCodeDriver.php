<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\External;

use Carbon\CarbonInterface;
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
 * A generic `code` driver for any system that can POST a JSON code-event
 * notification: a token-authenticated webhook. The body is a single event or a
 * list under `events`, each mapped to a {@see CodeReference}. Fields: `identifier`
 * (commit sha or PR number), `text` (message or title + body), optional `type`
 * (commit|pull_request|tag, default pull_request), `url`, `merged_at`, `base_ref`,
 * `head_ref`. The integration-free push counterpart to `sao:vcs:scan`.
 */
final readonly class WebhookCodeDriver implements CodeEventCapability, DriverInterface
{
    use ReadsExternalPayloads;

    private const string TOKEN_HEADER = 'x-code-token';

    #[Override]
    public function key(): string
    {
        return 'webhook-code';
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
            new ConfigurationField('secret', 'string', 'Shared token the sender sends in the X-Code-Token header', required: true, secret: true),
        ]);
    }

    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        $secret = self::stringOr($context->credentials['secret'] ?? '');

        return $secret === ''
            ? HealthCheckResult::unhealthy('No shared token configured for the code webhook connection.')
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

        $provided = $this->tokenHeader($headers);

        return $provided !== null && hash_equals($secret, $provided);
    }

    /**
     * @return list<CodeReference>
     */
    #[Override]
    public function unpack(BindingContext $context, string $payload): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true) ?? [];

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($decoded['events'] ?? null)
            ? array_values(array_filter($decoded['events'], 'is_array'))
            : [$decoded];

        $references = [];

        foreach ($rows as $row) {
            $identifier = self::stringOrNull($row['identifier'] ?? $row['number'] ?? $row['sha'] ?? null);
            $text = self::stringOrNull($row['text'] ?? null) ?? '';

            if ($identifier === null) {
                continue;
            }

            $references[] = new CodeReference(
                type: ChangeRefType::tryFrom(self::stringOr($row['type'] ?? '')) ?? ChangeRefType::PullRequest,
                identifier: $identifier,
                text: $text,
                url: self::stringOrNull($row['url'] ?? null),
                source: $this->key(),
                mergedAt: $this->dateOrNull($row['merged_at'] ?? null),
                baseRef: self::stringOrNull($row['base_ref'] ?? null),
                headRef: self::stringOrNull($row['head_ref'] ?? null),
                commitSha: self::stringOrNull($row['commit_sha'] ?? null),
            );
        }

        return $references;
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function tokenHeader(array $headers): ?string
    {
        foreach ($headers as $name => $value) {
            if (mb_strtolower($name) === self::TOKEN_HEADER) {
                return $value;
            }
        }

        return null;
    }

    private function dateOrNull(mixed $value): ?CarbonInterface
    {
        return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
    }
}
