<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\Role;
use Modules\Core\Models\User;
use Modules\Core\Tests\Support\HttpContext;
use Modules\SAO\Enums\Capability;
use Modules\SAO\Enums\CorrelationTarget;
use Modules\SAO\Enums\MatchType;
use Modules\SAO\Filament\Resources\CorrelationRules\CorrelationRuleResource;
use Modules\SAO\Filament\Resources\CorrelationRules\Pages\CreateCorrelationRule;
use Modules\SAO\Filament\Resources\CorrelationRules\Pages\ListCorrelationRules;
use Modules\SAO\Models\Connection;
use Modules\SAO\Models\CorrelationRule;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\SourceProfile;

uses(RefreshDatabase::class);

function correlationRulePanelUser(bool $superadmin): User
{
    if (! class_exists(App\Models\User::class)) {
        class_alias(User::class, App\Models\User::class);
    }

    $user = App\Models\User::query()->create(User::factory()->raw());

    if ($superadmin) {
        $user->assignRole(Role::findOrCreate(config('permission.roles.superadmin'), 'web'));
    }

    return $user;
}

test('the correlation rule resource is bound to its model under the SAO group', function (): void {
    expect(CorrelationRuleResource::getModel())->toBe(CorrelationRule::class)
        ->and(CorrelationRuleResource::getNavigationGroup())->toBe('sao.navigation.signals')
        ->and(CorrelationRuleResource::getSlug())->toBe('sao/correlation-rules')
        ->and(array_keys(CorrelationRuleResource::getPages()))->toBe(['index', 'create', 'edit']);
});

test('the list renders the rules ordered by priority, lower first', function (): void {
    $late = CorrelationRule::factory()->create(['priority' => 200]);
    $early = CorrelationRule::factory()->create(['priority' => 10]);
    $middle = CorrelationRule::factory()->create(['priority' => 100]);
    $this->actingAs(correlationRulePanelUser(superadmin: true));
    Filament::setCurrentPanel('admin');

    Livewire::test(ListCorrelationRules::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$early, $middle, $late], inOrder: true);
});

test('creating a rule stores target, match, pattern, project, environment and scope', function (): void {
    $project = Project::factory()->create();
    $connection = Connection::factory()->create([
        'driver_key' => 'glitchtip',
        'capabilities' => [Capability::Logs],
        'credential' => ['secret' => 'shared'],
        'base_url' => null,
    ]);
    $profile = SourceProfile::factory()->create();
    $this->actingAs(correlationRulePanelUser(superadmin: true));
    Filament::setCurrentPanel('admin');

    Livewire::test(CreateCorrelationRule::class)
        ->fillForm([
            'target' => CorrelationTarget::RequestHost->value,
            'match' => MatchType::Wildcard->value,
            'pattern' => '*.example.com',
            'project_id' => $project->getKey(),
            'environment' => 'staging',
            'connection_id' => $connection->getKey(),
            'source_profile_id' => $profile->getKey(),
            'priority' => 5,
            'is_active' => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $rule = CorrelationRule::query()->sole();

    expect($rule->target)->toBe(CorrelationTarget::RequestHost)
        ->and($rule->match)->toBe(MatchType::Wildcard)
        ->and($rule->pattern)->toBe('*.example.com')
        ->and($rule->project_id)->toBe($project->getKey())
        ->and($rule->environment)->toBe('staging')
        ->and($rule->connection_id)->toBe($connection->getKey())
        ->and($rule->source_profile_id)->toBe($profile->getKey())
        ->and($rule->priority)->toBe(5)
        ->and($rule->is_active)->toBeTrue();
});

test('the form requires a target, a match, a pattern and a project', function (): void {
    $this->actingAs(correlationRulePanelUser(superadmin: true));
    Filament::setCurrentPanel('admin');

    Livewire::test(CreateCorrelationRule::class)
        ->fillForm(['target' => null, 'match' => null, 'pattern' => null, 'project_id' => null])
        ->call('create')
        ->assertHasFormErrors(['target' => 'required', 'match' => 'required', 'pattern' => 'required', 'project_id' => 'required']);

    expect(CorrelationRule::query()->count())->toBe(0);
});

/**
 * The backoffice gates writes per table permission, the way every Laraplate resource does: the create
 * action needs `insert` and the edit action needs `update`. `permission:refresh` generates those rows
 * for the `sao_correlation_rules` table, so nothing is declared in the SAO permission registry.
 */
test('a user who may only read sees neither the create nor the edit action', function (): void {
    $rule = CorrelationRule::factory()->create();
    HttpContext::panelActorWithoutApproval(new CorrelationRule, ['select']);

    Livewire::test(ListCorrelationRules::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$rule])
        ->assertActionDoesNotExist('create')
        ->assertTableActionDoesNotExist('edit', record: $rule);
});

test('a user who may insert and update sees the create and edit actions', function (): void {
    $rule = CorrelationRule::factory()->create();
    HttpContext::panelActorWithoutApproval(new CorrelationRule, ['select', 'insert', 'update']);

    Livewire::test(ListCorrelationRules::class)
        ->loadTable()
        ->assertActionExists('create')
        ->assertTableActionExists('edit', record: $rule);
});
