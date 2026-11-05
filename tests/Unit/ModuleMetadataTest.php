<?php

declare(strict_types=1);

/**
 * Phase 0 compliance: the module must declare the same identity, licence and
 * dependency shape as the other Laraplate modules.
 */
function sao_module_root(): string
{
    return dirname(__DIR__, 2);
}

/**
 * @return array<string, mixed>
 */
function sao_read_json(string $relative_path): array
{
    $contents = file_get_contents(sao_module_root() . '/' . $relative_path);

    expect($contents)->not->toBeFalse("{$relative_path} must be readable");

    /** @var array<string, mixed> */
    return json_decode((string) $contents, true, 512, JSON_THROW_ON_ERROR);
}

test('module.json declares Laraplate ownership and the Core dependency', function (): void {
    $config = sao_read_json('module.json');

    expect($config)->toHaveKeys(['name', 'alias', 'description', 'keywords', 'providers', 'requires']);
    expect($config['name'])->toBe('SAO');
    expect($config['alias'])->toBe('sao');
    expect($config['laraplate_owned'] ?? null)->toBeTrue();
    expect($config['description'])->not->toBe('');
    expect($config['requires'])->toBeArray()->toContain('Core');
    expect($config['providers'])->toContain('Modules\\SAO\\Providers\\SAOServiceProvider');
});

test('module.json does not declare the AI module before phase 8', function (): void {
    $config = sao_read_json('module.json');

    /** @var list<string> $requires */
    $requires = $config['requires'];

    expect($requires)->not->toContain('AI');
});

test('composer.json declares the agreed package identity and licence', function (): void {
    $config = sao_read_json('composer.json');

    expect($config['name'])->toBe('getlaraplate/larasao');
    expect($config['vendor'] ?? null)->toBe('getlaraplate');
    expect($config['license'])->toBe('AGPL-3.0-or-later');
    expect($config['type'])->toBe('laravel-module');
    expect($config['version'] ?? null)->toBeString();
});

test('composer.json declares the platform floors', function (): void {
    $config = sao_read_json('composer.json');

    /** @var array<string, string> $require */
    $require = $config['require'];

    expect($require['php'])->toBe('>=8.5');
    expect($require['laravel/framework'])->toBe('^13.0');
});

test('composer.json declares no test or quality script', function (): void {
    $config = sao_read_json('composer.json');

    /** @var array<string, mixed> $scripts */
    $scripts = $config['scripts'] ?? [];

    // Those scripts called a relative vendor/bin that a module has never had. They are the
    // application's, and composer-merge-plugin used to surface them at the root as if they
    // were the root's own.
    foreach (array_keys($scripts) as $name) {
        expect(str_starts_with((string) $name, 'test'))->toBeFalse("The application owns the {$name} script");
        expect(str_starts_with((string) $name, 'version'))->toBeFalse("The application owns the {$name} script");
    }

    expect($scripts)->not->toHaveKey('lint');
    expect($scripts)->not->toHaveKey('check');
    expect($scripts)->not->toHaveKey('setup:hooks');
});

test('composer.json declares no development dependency', function (): void {
    $config = sao_read_json('composer.json');

    // A module's require-dev used to feed the application's vendor/ through composer-merge-plugin,
    // which meant six files decided which Pest the project ran and the loser was dropped silently.
    expect($config)->not->toHaveKey('require-dev');
});

test('composer.json maps the module and test namespaces', function (): void {
    $config = sao_read_json('composer.json');

    /** @var array{'psr-4': array<string, string>} $autoload */
    $autoload = $config['autoload'];

    expect($autoload['psr-4'])->toHaveKeys([
        'Modules\\SAO\\',
        'Modules\\SAO\\Database\\Factories\\',
        'Modules\\SAO\\Database\\Seeders\\',
    ]);

    /** @var array{'psr-4': array<string, string>, classmap: list<string>} $autoload_dev */
    $autoload_dev = $config['autoload-dev'];

    expect($autoload_dev['psr-4'])->toHaveKeys([
        'Modules\\SAO\\Tests\\Unit\\',
        'Modules\\SAO\\Tests\\Feature\\',
    ]);
    expect($autoload_dev['classmap'])->toContain('tests/TestCase.php');
});
