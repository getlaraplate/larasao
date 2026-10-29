<?php

declare(strict_types=1);

use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\MatchType;

test('an exact match compares the whole string case-insensitively', function (): void {
    expect(MatchType::Exact->matches('Api.Example.com', 'api.example.com'))->toBeTrue()
        ->and(MatchType::Exact->matches('api.example.com', 'api.example.com.au'))->toBeFalse();
});

test('a wildcard star matches any sequence and the dot is literal', function (string $pattern, string $value, bool $expected): void {
    expect(MatchType::Wildcard->matches($pattern, $value))->toBe($expected);
})->with([
    'star at start' => ['*.example.com', 'a.b.example.com', true],
    'bare domain is not a subdomain' => ['*.example.com', 'example.com', false],
    'no dot boundary' => ['*.example.com', 'xexample.com', false],
    'the dot is not a regex dot' => ['a.example.com', 'abexample.com', false],
    'star at end' => ['api-*', 'api-eu1', true],
    'star at both ends' => ['*prod*', 'my-prod-host', true],
    'star in the middle' => ['api-*-host', 'api-eu1-host', true],
    'case-insensitive' => ['*.EXAMPLE.com', 'Shop.Example.COM', true],
    'question mark is literal' => ['api?', 'apix', false],
    'question mark matches itself' => ['api?', 'api?', true],
]);

test('a project key is read as is, empty or missing gives null', function (): void {
    expect(CorrelationTarget::ProjectKey->valueFrom(['project_key' => 'WIDGETS']))->toBe('WIDGETS')
        ->and(CorrelationTarget::ProjectKey->valueFrom(['project_key' => '']))->toBeNull()
        ->and(CorrelationTarget::ProjectKey->valueFrom([]))->toBeNull();
});

test('a server host is read as is, missing gives null', function (): void {
    expect(CorrelationTarget::ServerHost->valueFrom(['server_host' => 'web-01']))->toBe('web-01')
        ->and(CorrelationTarget::ServerHost->valueFrom([]))->toBeNull();
});

test('a request host is the lowercased host of the request url', function (): void {
    expect(CorrelationTarget::RequestHost->valueFrom(['request_url' => 'https://Shop.Example.com:8443/a?b=1']))->toBe('shop.example.com')
        ->and(CorrelationTarget::RequestHost->valueFrom(['request_url' => 'not a url']))->toBeNull()
        ->and(CorrelationTarget::RequestHost->valueFrom([]))->toBeNull();
});
