<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\SAO\Filament\Resources\Environments\Pages\CreateEnvironment;
use Modules\SAO\Models\Environment;
use Modules\SAO\Models\Project;

uses(RefreshDatabase::class);

function environmentPanelUser(): User
{
    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    $user = App\Models\User::query()->create(User::factory()->raw());
    $user->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));

    return $user;
}

test('creating an environment stores its base url, commit and branch', function (): void {
    $project = Project::factory()->create();
    $this->actingAs(environmentPanelUser());
    Filament::setCurrentPanel('admin');

    Livewire::test(CreateEnvironment::class)
        ->fillForm([
            'project_id' => $project->getKey(),
            'name' => 'production',
            'base_url' => 'https://app.example.com',
            'current_commit' => 'abc1234',
            'current_branch' => 'main',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $environment = Environment::query()->sole();

    expect($environment->base_url)->toBe('https://app.example.com')
        ->and($environment->current_commit)->toBe('abc1234')
        ->and($environment->current_branch)->toBe('main');
});

test('the base url accepts only http and https', function (string $url): void {
    $project = Project::factory()->create();
    $this->actingAs(environmentPanelUser());
    Filament::setCurrentPanel('admin');

    Livewire::test(CreateEnvironment::class)
        ->fillForm([
            'project_id' => $project->getKey(),
            'name' => 'production',
            'base_url' => $url,
        ])
        ->call('create')
        ->assertHasFormErrors(['base_url']);

    expect(Environment::query()->count())->toBe(0);
})->with(['ftp://example.com', 'file:///etc/passwd', 'not a url']);
