<?php

declare(strict_types=1);

namespace Modules\SAO\Drivers\External;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Modules\SAO\Drivers\Contracts\DriverInterface;
use Modules\SAO\Drivers\Contracts\VersionProbeCapability;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConfigurationField;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Drivers\Support\DriverConfigurationSchema;
use Modules\SAO\Drivers\Support\HealthCheckResult;
use Modules\SAO\Drivers\Support\ProbedVersion;
use Modules\SAO\Drivers\Support\ReadsExternalPayloads;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Modules\SAO\Exceptions\ProbeException;
use Modules\SAO\Models\Environment;
use Override;

/**
 * The `probe` driver that reads an application's running version over HTTP: a
 * GET of the environment's own `base_url` plus a path, expecting a JSON object.
 *
 * Safety is the point of the shape. Only the operator-set `Environment.base_url`
 * is requested (never an address that arrived in a payload), the scheme is http
 * or https, redirects are not followed (a 3xx is a failure), the timeout is
 * bounded, and the optional `auth_header` credential is validated before any
 * request and never echoed. Non-secret settings (`path`, the dot-paths and the
 * `timeout`) come from the connection's config, and a binding's config overrides
 * any of them.
 */
final readonly class HttpVersionDriver implements DriverInterface, VersionProbeCapability
{
    use ReadsExternalPayloads;

    private const string DEFAULT_PATH = '/version';

    private const string DEFAULT_VERSION_PATH = 'version';

    private const int DEFAULT_TIMEOUT_SECONDS = 5;

    private const string HEADER_NAME_PATTERN = "/^[A-Za-z0-9!#$%&'*+.^_`|~-]+$/";

    #[Override]
    public function key(): string
    {
        return 'http-version';
    }

    /**
     * @return list<Capability>
     */
    #[Override]
    public function capabilities(): array
    {
        return [Capability::Probe];
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
        return new DriverConfigurationSchema([
            new ConfigurationField('path', 'string', 'Path requested on the environment base URL (default /version)'),
            new ConfigurationField('version_path', 'string', 'Dot path of the version in the JSON response (default version)'),
            new ConfigurationField('commit_path', 'string', 'Dot path of the commit in the JSON response'),
            new ConfigurationField('branch_path', 'string', 'Dot path of the branch in the JSON response'),
            new ConfigurationField('timeout', 'integer', 'Request timeout in seconds (default 5)'),
            new ConfigurationField('auth_header', 'string', 'Optional header sent with the probe, as "Name: value"', secret: true),
        ]);
    }

    /**
     * Makes no network call: the probed address belongs to each environment, so
     * there is nothing connection-wide to reach. It checks what it can see: a
     * malformed auth header, or a connection address that is not http(s).
     */
    #[Override]
    public function healthCheck(ConnectionContext $context): HealthCheckResult
    {
        $baseUrl = self::stringOr($context->baseUrl);

        if ($baseUrl !== '' && ! in_array(parse_url($baseUrl, PHP_URL_SCHEME), ['http', 'https'], true)) {
            return HealthCheckResult::unhealthy('The connection address must use http or https.');
        }

        try {
            $this->authHeader($context);
        } catch (ProbeException $exception) {
            return HealthCheckResult::unhealthy($exception->getMessage());
        }

        return HealthCheckResult::healthy('Probes use each environment base URL; no request is made here.');
    }

    /**
     * @throws ProbeException
     */
    #[Override]
    public function probe(BindingContext $context, Environment $environment): ?ProbedVersion
    {
        $settings = $this->settings($context);
        $url = $this->url($environment, self::stringOr($settings['path'] ?? null, self::DEFAULT_PATH));
        $header = $this->authHeader($context->connection);

        $request = Http::timeout($this->timeout($settings))->withoutRedirecting()->acceptJson();

        if ($header !== null) {
            $request = $request->withHeaders($header);
        }

        try {
            $response = $request->get($url);
        } catch (ConnectionException) {
            throw ProbeException::unreachable();
        } catch (InvalidArgumentException) {
            // Guzzle refuses a URL it cannot parse; that is a bad environment address.
            throw ProbeException::invalidBaseUrl('it is not a valid URL');
        }

        if (! $response->successful()) {
            throw ProbeException::badStatus($response->status());
        }

        $payload = $response->json();

        if (! is_array($payload) || ($payload !== [] && array_is_list($payload))) {
            throw ProbeException::notJsonObject();
        }

        $versionPath = self::stringOr($settings['version_path'] ?? null, self::DEFAULT_VERSION_PATH);
        $version = $this->version(data_get($payload, $versionPath));

        if ($version === null) {
            throw ProbeException::versionMissing($versionPath);
        }

        return new ProbedVersion(
            $version,
            $this->optionalString($payload, $settings['commit_path'] ?? null),
            $this->optionalString($payload, $settings['branch_path'] ?? null),
        );
    }

    /**
     * The only address ever requested: the environment's own base URL plus the
     * path. Anything but a plain http(s) URL with a host is refused up front.
     *
     * @throws ProbeException
     */
    private function url(Environment $environment, string $path): string
    {
        $baseUrl = mb_trim(self::stringOr($environment->base_url));

        if ($baseUrl === '') {
            throw ProbeException::invalidBaseUrl('it is empty');
        }

        if (preg_match('/[\s\x00-\x1f\x7f]/', $baseUrl) === 1) {
            throw ProbeException::invalidBaseUrl('it contains whitespace or control characters');
        }

        $parts = parse_url($baseUrl);

        if ($parts === false || ! in_array($parts['scheme'] ?? null, ['http', 'https'], true)) {
            throw ProbeException::invalidBaseUrl('only http and https are allowed');
        }

        if (self::stringOr($parts['host'] ?? null) === '') {
            throw ProbeException::invalidBaseUrl('it has no host');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw ProbeException::invalidBaseUrl('it must not embed credentials');
        }

        return mb_rtrim($baseUrl, '/') . '/' . mb_ltrim($path, '/');
    }

    /**
     * @throws ProbeException
     *
     * @return array<string, string>|null
     */
    private function authHeader(ConnectionContext $context): ?array
    {
        $raw = $context->credentials['auth_header'] ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw) || ! str_contains($raw, ':')) {
            throw ProbeException::malformedAuthHeader();
        }

        [$name, $value] = explode(':', $raw, 2);
        $name = mb_trim($name);
        $value = mb_trim($value);

        if ($name === '' || preg_match(self::HEADER_NAME_PATTERN, $name) !== 1 || preg_match('/[\r\n\0]/', $value) === 1) {
            throw ProbeException::malformedAuthHeader();
        }

        return [$name => $value];
    }

    /**
     * The connection's config supplies the defaults; any key the binding sets
     * (non-empty) overrides it.
     *
     * @return array<string, mixed>
     */
    private function settings(BindingContext $context): array
    {
        $overrides = array_filter(
            $context->config,
            static fn (mixed $value): bool => $value !== null && $value !== '',
        );

        return $overrides + $context->connection->config;
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function timeout(array $settings): int
    {
        return max(1, self::intOr($settings['timeout'] ?? null, self::DEFAULT_TIMEOUT_SECONDS));
    }

    /**
     * A version is a non-empty string or an integer. A float is refused because
     * it cannot round-trip (1.10 decodes as 1.1), and booleans or structures
     * are not versions.
     */
    private function version(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     */
    private function optionalString(array $payload, mixed $path): ?string
    {
        if (! is_string($path) || $path === '') {
            return null;
        }

        $value = data_get($payload, $path);

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }
}
