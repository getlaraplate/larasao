<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\SAO\Drivers\DriverRegistry;
use Modules\SAO\Drivers\External\HttpVersionDriver;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Modules\SAO\Exceptions\ProbeException;
use Modules\SAO\Models\Environment;
use Modules\SAO\Tests\Support\Conformance\ProbeConformance;

/**
 * @param  array<string, mixed>  $config
 */
function probeContext(array $config = [], string $authHeader = ''): BindingContext
{
    return new BindingContext(
        new ConnectionContext(baseUrl: null, credentials: $authHeader === '' ? [] : ['auth_header' => $authHeader]),
        config: $config,
    );
}

/**
 * @param  array<string, mixed>  $connectionConfig
 * @param  array<string, mixed>  $bindingConfig
 */
function probeContextWithConnectionConfig(array $connectionConfig, array $bindingConfig = []): BindingContext
{
    return new BindingContext(
        new ConnectionContext(baseUrl: null, credentials: [], config: $connectionConfig),
        config: $bindingConfig,
    );
}

function probeEnvironment(?string $baseUrl = 'https://shop.example.com'): Environment
{
    return new Environment(['name' => 'production', 'base_url' => $baseUrl]);
}

function versionPayload(): array
{
    return ['app' => ['version' => '1.4.0', 'git' => ['sha' => 'abc', 'ref' => 'main']]];
}

test('the http-version driver declares the probe capability with pull ingest and is registered', function (): void {
    $driver = new HttpVersionDriver;

    expect($driver->key())->toBe('http-version')
        ->and($driver->capabilities())->toBe([Capability::Probe])
        ->and($driver->ingestModes())->toBe([IngestMode::Pull])
        ->and($driver->configurationSchema()->field('auth_header')?->secret)->toBeTrue()
        ->and($driver->configurationSchema()->field('path'))->not->toBeNull()
        ->and(app(DriverRegistry::class)->has('http-version'))->toBeTrue();
});

test('the http-version driver passes the probe conformance battery', function (): void {
    Http::fake(['*' => Http::response(versionPayload())]);

    ProbeConformance::assert(new HttpVersionDriver, probeContext(['version_path' => 'app.version']), probeEnvironment());
});

test('dot paths pick the version, commit and branch out of the response', function (): void {
    Http::fake(['*' => Http::response(versionPayload())]);

    $probed = (new HttpVersionDriver)->probe(
        probeContext(['version_path' => 'app.version', 'commit_path' => 'app.git.sha', 'branch_path' => 'app.git.ref']),
        probeEnvironment(),
    );

    expect($probed->version)->toBe('1.4.0')
        ->and($probed->commit)->toBe('abc')
        ->and($probed->branch)->toBe('main');

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://shop.example.com/version');
});

test('commit and branch are optional and null when absent or not strings', function (): void {
    Http::fake(['*' => Http::response(['version' => '2.0.0', 'sha' => ['nested'], 'branch' => 7])]);

    $probed = (new HttpVersionDriver)->probe(
        probeContext(['commit_path' => 'sha', 'branch_path' => 'branch']),
        probeEnvironment(),
    );

    expect($probed->version)->toBe('2.0.0')
        ->and($probed->commit)->toBeNull()
        ->and($probed->branch)->toBeNull();
});

test('an integer version is accepted as a string, a float or boolean is not', function (): void {
    Http::fake(['*' => Http::sequence()
        ->push(['version' => 42])
        ->push(['version' => 1.10])
        ->push(['version' => true])]);

    $driver = new HttpVersionDriver;

    expect($driver->probe(probeContext(), probeEnvironment())->version)->toBe('42')
        ->and(fn () => $driver->probe(probeContext(), probeEnvironment()))->toThrow(ProbeException::class)
        ->and(fn () => $driver->probe(probeContext(), probeEnvironment()))->toThrow(ProbeException::class);
});

test('the binding path override is requested instead of the default', function (): void {
    Http::fake(['*' => Http::response(['version' => '3.1.0'])]);

    (new HttpVersionDriver)->probe(probeContext(['path' => 'api/health/version']), probeEnvironment('https://shop.example.com/'));

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://shop.example.com/api/health/version');
});

test('the auth header is sent as a Name: value pair', function (): void {
    Http::fake(['*' => Http::response(['version' => '1.0.0'])]);

    (new HttpVersionDriver)->probe(probeContext(authHeader: 'X-Probe-Token: s3cret'), probeEnvironment());

    Http::assertSent(static fn (Request $request): bool => ['s3cret'] === $request->header('X-Probe-Token'));
});

test('a malformed auth header fails before any request and never appears in the message', function (string $header): void {
    Http::fake();

    try {
        (new HttpVersionDriver)->probe(probeContext(authHeader: $header), probeEnvironment());
        $this->fail('Expected a ProbeException.');
    } catch (ProbeException $exception) {
        expect($exception->getMessage())->not->toContain('s3cret');
    }

    Http::assertNothingSent();
})->with([
    'no colon' => 's3cret',
    'empty name' => ': s3cret',
    'invalid name' => 'Bad Name: s3cret',
    'newline in value' => "X-Token: s3cret\r\nX-Evil: 1",
]);

test('the timeout is passed to the client and defaults to five seconds', function (): void {
    $timeouts = [];
    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response(['version' => '1.0.0']);
    });

    $driver = new HttpVersionDriver;
    $driver->probe(probeContext(), probeEnvironment());
    $driver->probe(probeContext(['timeout' => 12]), probeEnvironment());

    expect($timeouts)->toBe([5, 12]);
});

test('redirects are not followed and a 3xx is a failure', function (): void {
    $allowRedirects = 'unset';
    Http::fake(function (Request $request, array $options) use (&$allowRedirects) {
        $allowRedirects = $options['allow_redirects'] ?? 'unset';

        return Http::response('', 302, ['Location' => 'http://169.254.169.254/']);
    });

    expect(fn () => (new HttpVersionDriver)->probe(probeContext(), probeEnvironment()))
        ->toThrow(ProbeException::class, 'HTTP 302');

    expect($allowRedirects)->toBeFalse();
    Http::assertSentCount(1);
});

test('a non-2xx answer is a failure', function (int $status): void {
    Http::fake(['*' => Http::response(['version' => '1.0.0'], $status)]);

    expect(fn () => (new HttpVersionDriver)->probe(probeContext(), probeEnvironment()))
        ->toThrow(ProbeException::class, "HTTP {$status}");
})->with([404, 500, 503]);

test('a connection failure or timeout becomes a short probe failure without the transport message', function (): void {
    Http::fake(['*' => static fn () => throw new ConnectionException('cURL error 28: timed out for https://user:hunter2@shop.example.com')]);

    try {
        (new HttpVersionDriver)->probe(probeContext(), probeEnvironment());
        $this->fail('Expected a ProbeException.');
    } catch (ProbeException $exception) {
        expect($exception->getMessage())->not->toContain('hunter2')->not->toContain('cURL');
    }
});

test('an empty, non-http or hostless base url is refused without a request', function (?string $baseUrl): void {
    Http::fake();

    expect(fn () => (new HttpVersionDriver)->probe(probeContext(), probeEnvironment($baseUrl)))
        ->toThrow(ProbeException::class);

    Http::assertNothingSent();
})->with([
    'null' => [null],
    'empty' => [''],
    'ftp' => ['ftp://shop.example.com'],
    'file' => ['file:///etc/passwd'],
    'no scheme' => ['shop.example.com'],
    'no host' => ['https://'],
    'userinfo' => ['https://user:pass@shop.example.com'],
    'space in host' => ['https://exa mple.com'],
    'unterminated ipv6' => ['http://[::1'],
    'two ports' => ['https://a.b:80:90'],
]);

test('a response that is not a JSON object with a version is a failure', function (mixed $body): void {
    Http::fake(['*' => Http::response($body)]);

    expect(fn () => (new HttpVersionDriver)->probe(probeContext(), probeEnvironment()))
        ->toThrow(ProbeException::class);
})->with([
    'html' => ['<html><body>Welcome</body></html>'],
    'json string' => ['"1.0.0"'],
    'json number' => ['42'],
    'json list' => [['1.0.0']],
    'object without the version' => [['name' => 'shop']],
    'empty version' => [['version' => '']],
    'nested version' => [['version' => ['major' => 1]]],
]);

test('the health check needs no network and flags a malformed auth header', function (): void {
    Http::fake();

    $driver = new HttpVersionDriver;

    expect($driver->healthCheck(new ConnectionContext(null, []))->healthy)->toBeTrue()
        ->and($driver->healthCheck(new ConnectionContext(null, ['auth_header' => 'X-Token: abc']))->healthy)->toBeTrue()
        ->and($driver->healthCheck(new ConnectionContext(null, ['auth_header' => 'no-colon']))->healthy)->toBeFalse()
        ->and($driver->healthCheck(new ConnectionContext('ftp://x', []))->healthy)->toBeFalse();

    Http::assertNothingSent();
});

test('connection config supplies the defaults and the binding config overrides any key', function (): void {
    $urls = [];
    $timeouts = [];
    Http::fake(function (Request $request, array $options) use (&$urls, &$timeouts) {
        $urls[] = $request->url();
        $timeouts[] = $options['timeout'] ?? null;

        return Http::response(['info' => ['v' => '5.0.0', 'sha' => 'f00', 'ref' => 'main'], 'version' => 'wrong']);
    });

    $connection = ['path' => '/status', 'version_path' => 'info.v', 'commit_path' => 'info.sha', 'branch_path' => 'info.ref', 'timeout' => 9];
    $driver = new HttpVersionDriver;

    $fromConnection = $driver->probe(probeContextWithConnectionConfig($connection), probeEnvironment());
    $overridden = $driver->probe(probeContextWithConnectionConfig($connection, ['path' => '/health', 'timeout' => 2, 'commit_path' => 'nope']), probeEnvironment());

    expect($fromConnection->version)->toBe('5.0.0')
        ->and($fromConnection->commit)->toBe('f00')
        ->and($fromConnection->branch)->toBe('main')
        ->and($overridden->version)->toBe('5.0.0')
        ->and($overridden->commit)->toBeNull()
        ->and($overridden->branch)->toBe('main')
        ->and($urls)->toBe(['https://shop.example.com/status', 'https://shop.example.com/health'])
        ->and($timeouts)->toBe([9, 2]);
});
