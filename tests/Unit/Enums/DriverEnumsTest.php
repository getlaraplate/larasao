<?php

declare(strict_types=1);

use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\IngestMode;

test('capabilities are the families with their wire values', function (): void {
    expect(Capability::values())->toEqualCanonicalizing(['vcs', 'issues', 'releases', 'logs', 'deploy', 'code', 'probe'])
        ->and(Capability::from('issues'))->toBe(Capability::Issues)
        ->and(Capability::from('vcs'))->toBe(Capability::Vcs)
        ->and(Capability::from('releases'))->toBe(Capability::Releases)
        ->and(Capability::from('logs'))->toBe(Capability::Logs)
        ->and(Capability::from('deploy'))->toBe(Capability::Deploy)
        ->and(Capability::from('code'))->toBe(Capability::Code)
        ->and(Capability::from('probe'))->toBe(Capability::Probe);
});

test('ingest modes are push, pull and in_process', function (): void {
    expect(IngestMode::values())->toEqualCanonicalizing(['push', 'pull', 'in_process'])
        ->and(IngestMode::from('in_process'))->toBe(IngestMode::InProcess);
});

test('an unknown wire value throws', function (): void {
    expect(fn (): Capability => Capability::from('bogus'))->toThrow(ValueError::class);
});

test('both driver enums expose an in: validation rule', function (string $rule): void {
    expect($rule)->toStartWith('in:');
})->with([
    fn (): string => Capability::validationRule(),
    fn (): string => IngestMode::validationRule(),
]);
