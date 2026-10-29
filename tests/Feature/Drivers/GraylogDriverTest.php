<?php

declare(strict_types=1);

use Modules\SAO\Drivers\External\GraylogDriver;
use Modules\SAO\Drivers\Support\BindingContext;
use Modules\SAO\Drivers\Support\ConnectionContext;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;
use Modules\SAO\Ingest\GroupKeyResolver;

function graylogContext(): BindingContext
{
    return new BindingContext(
        new ConnectionContext(baseUrl: 'https://graylog.example.com', credentials: ['secret' => 'shared-token']),
        remoteIdentifier: 'acme',
    );
}

function graylogPayload(): string
{
    return json_encode([
        'event_definition_title' => 'High error rate',
        'event' => ['priority' => 2, 'source' => 'web-01', 'timestamp' => '2026-08-16T10:00:00Z'],
        'backlog' => [
            ['message' => 'RuntimeException: queue stalled', 'source' => 'web-01', 'level' => 3, 'timestamp' => '2026-08-16T09:59:00Z', 'fields' => ['application_url' => 'https://shop.example.com/cart']],
            ['message' => 'RuntimeException: queue stalled', 'source' => 'web-02', 'level' => 3, 'timestamp' => '2026-08-16T09:59:30Z'],
        ],
    ], JSON_THROW_ON_ERROR);
}

test('the graylog driver declares logs with push ingest and no native key', function (): void {
    $driver = new GraylogDriver;

    expect($driver->key())->toBe('graylog')
        ->and($driver->capabilities())->toBe([Capability::Logs])
        ->and($driver->ingestModes())->toBe([IngestMode::Push])
        ->and($driver->carriesNativeGroupKey())->toBeFalse();
});

test('a delivery with the shared token verifies, a wrong or missing one does not', function (): void {
    $driver = new GraylogDriver;
    $payload = graylogPayload();

    expect($driver->verifySignature(graylogContext(), $payload, ['X-Graylog-Token' => 'shared-token']))->toBeTrue()
        ->and($driver->verifySignature(graylogContext(), $payload, ['X-Graylog-Token' => 'wrong']))->toBeFalse()
        ->and($driver->verifySignature(graylogContext(), $payload, []))->toBeFalse();
});

test('unpacking yields one event per backlog message, source-tagged, without a native key', function (): void {
    $page = (new GraylogDriver)->unpack(graylogContext(), graylogPayload());

    expect($page->items)->toHaveCount(2);

    $first = $page->items[0];

    expect($first['source'])->toBe('graylog')
        ->and($first['message'])->toBe('RuntimeException: queue stalled')
        ->and($first['server_host'])->toBe('web-01')
        ->and($first['request_url'])->toBe('https://shop.example.com/cart')
        ->and($first)->not->toHaveKey('environment')
        ->and($first)->not->toHaveKey('native_key')
        ->and($page->items[1]['request_url'])->toBeNull();
});

test('the resolver computes a fingerprint key for a graylog event, not a namespaced native one', function (): void {
    $event = (new GraylogDriver)->unpack(graylogContext(), graylogPayload())->items[0];
    $key = app(GroupKeyResolver::class)->resolve($event);

    expect($key)->toBeString()->not->toBe('')
        ->and($key)->not->toStartWith('graylog:');
});

test('an event definition with no backlog falls back to its title', function (): void {
    $payload = json_encode([
        'event_definition_title' => 'Disk almost full',
        'event' => ['source' => 'db-01', 'timestamp' => '2026-08-16T10:00:00Z'],
        'backlog' => [],
    ], JSON_THROW_ON_ERROR);

    $page = (new GraylogDriver)->unpack(graylogContext(), $payload);

    expect($page->items)->toHaveCount(1)
        ->and($page->items[0]['message'])->toBe('Disk almost full');
});
