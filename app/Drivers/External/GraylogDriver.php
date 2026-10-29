<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\External;

use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\Contracts\LogsCapability;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConfigurationField;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Drivers\Support\DriverConfigurationSchema;
use Modules\SAO\Drivers\Support\HealthCheckResult;
use Modules\SAO\Drivers\Support\Page;
use Modules\SAO\Drivers\Support\ReadsExternalPayloads;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Override;

/**
 * The `logs` driver for Graylog, over its HTTP event-notification (push)
 * transport. Unlike an error tracker, Graylog is a log/alert aggregator with no
 * stable native grouping key, so `carriesNativeGroupKey()` is false and each
 * unpacked event is fingerprinted by SAO's own chain (spec §5). Deliveries are
 * authenticated by a shared token that Graylog is configured to send in a
 * header, compared to the connection secret.
 */
final readonly class GraylogDriver implements DriverInterface, LogsCapability
{
    use ReadsExternalPayloads;

    private const string TOKEN_HEADER = 'x-graylog-token';

    #[Override]
    public function key(): string
    {
        return 'graylog';
    }

    /**
     * @return list<Capability>
     */
    #[Override]
    public function capabilities(): array
    {
        return [Capability::Logs];
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
            new ConfigurationField('secret', 'string', 'Shared token Graylog sends in the notification header', required: true, secret: true),
        ]);
    }

    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        $secret = self::stringOr($context->credentials['secret'] ?? '');

        return $secret === ''
            ? HealthCheckResult::unhealthy('No shared token configured for the Graylog connection.')
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

    #[Override]
    public function unpack(BindingContext $context, string $payload): Page
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($payload, true) ?? [];

        $title = self::stringOrNull($decoded['event_definition_title'] ?? null);

        /** @var array<string, mixed> $event */
        $event = is_array($decoded['event'] ?? null) ? $decoded['event'] : [];

        /** @var list<array<string, mixed>> $backlog */
        $backlog = is_array($decoded['backlog'] ?? null) ? array_values(array_filter($decoded['backlog'], 'is_array')) : [];

        // A Graylog notification carries a backlog of the matching messages;
        // each becomes an event. With no backlog the event itself is the record.
        $rows = $backlog === [] ? [$event] : $backlog;

        $items = [];

        foreach ($rows as $row) {
            $message = self::stringOrNull($row['message'] ?? null) ?? $title;

            if ($message === null || $message === '') {
                continue;
            }

            $items[] = [
                'source' => $this->key(),
                'message' => $message,
                'level' => self::stringOrNull($row['level'] ?? $event['priority'] ?? null),
                'server_host' => self::stringOrNull($row['source'] ?? $event['source'] ?? null),
                'request_url' => self::stringOrNull(is_array($row['fields'] ?? null) ? ($row['fields']['application_url'] ?? null) : null),
                'occurred_at' => self::stringOrNull($row['timestamp'] ?? $event['timestamp'] ?? null),
                'raw' => $row,
            ];
        }

        return new Page($items);
    }

    #[Override]
    public function carriesNativeGroupKey(): bool
    {
        return false;
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
}
