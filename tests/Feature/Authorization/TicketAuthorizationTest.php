<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Casts\ActionEnum;
use Modules\Core\Models\Permission;
use Modules\Core\Support\PermissionName;
use Modules\SAO\Database\Seeders\SAOPermissionSeeder;
use Modules\SAO\Models\Project;
use Modules\SAO\Models\Ticket;
use Modules\SAO\Services\TicketQueryService;

uses(RefreshDatabase::class);

test('permission names follow the Laraplate convention', function (): void {
    expect(PermissionName::forClass(Ticket::class, 'transition'))
        ->toBe('default.sao_tickets.transition');
    expect(PermissionName::forClass(Project::class, ActionEnum::Select->value))
        ->toBe('default.sao_projects.select');
});

/**
 * Only the verbs the domain adds. The CRUD ones belong to `permission:refresh`,
 * which generates them for every managed table, so seeding them here as well
 * would be declaring the same row twice.
 */
test('the seeder registers every SAO domain permission', function (): void {
    $this->seed(SAOPermissionSeeder::class);

    $expected = [
        'default.sao_tickets.transition',
        'default.sao_tickets.transition_override',
        'default.sao_tickets.close',
        'default.sao_ownership_suggestions.accept',
        'default.sao_connections.health',
        'default.sao_ingest_events.replay',
        'default.sao_ingest_events.reprocess',
    ];

    foreach ($expected as $name) {
        expect(Permission::query()->where('name', $name)->exists())
            ->toBeTrue("Missing permission: {$name}");
    }
});

test('the seeder leaves the CRUD verbs to permission:refresh', function (): void {
    $this->seed(SAOPermissionSeeder::class);

    $crud = [
        'default.sao_tickets.select',
        'default.sao_tickets.insert',
        'default.sao_tickets.update',
        'default.sao_tickets.delete',
        'default.sao_projects.select',
    ];

    foreach ($crud as $name) {
        expect(Permission::query()->where('name', $name)->exists())
            ->toBeFalse("Seeder should not create: {$name}");
    }
});

/**
 * The verbs SAO used to seed and no longer does. `view` was a second read anchor
 * on a table Core already reads with `select`, which meant two ACL sets on one
 * table and one of them always unconfigured; `create` was never checked at all.
 * `assign` had no consumer either: no handler, no policy method, no gate. The
 * assignee is written through `update` like any other column, and the sanctioned
 * assignment path carries `sao_ownership_suggestions.accept`.
 */
test('the retired Filament verbs are gone for good', function (): void {
    $this->seed(SAOPermissionSeeder::class);

    $retired = [
        'default.sao_tickets.view',
        'default.sao_tickets.create',
        'default.sao_projects.view',
        'default.sao_projects.create',
        'default.sao_tickets.assign',
    ];

    foreach ($retired as $name) {
        expect(Permission::query()->where('name', $name)->exists())
            ->toBeFalse("Retired permission still seeded: {$name}");
    }
});

test('seeding twice does not duplicate permissions', function (): void {
    $this->seed(SAOPermissionSeeder::class);
    $before = Permission::query()->where('name', 'like', 'default.sao_%')->count();

    $this->seed(SAOPermissionSeeder::class);

    expect(Permission::query()->where('name', 'like', 'default.sao_%')->count())->toBe($before);
});

/**
 * Core's ACL filtering is not automatic at the Eloquent level — HasACL's global
 * scope is an unimplemented TODO — so a service that queries tickets with raw
 * Eloquent would silently bypass row-level visibility. This locks in the
 * existence of the one sanctioned read path, so a reviewer can grep for
 * Ticket::query() outside it. That a restricting ACL actually hides rows is
 * proven separately, in TicketVisibilityTest.
 */
test('the ticket read path is a service that hands the query to the ACL layer', function (): void {
    Ticket::factory()->count(3)->create();

    $query = app(TicketQueryService::class)->visible();

    expect($query->getModel())->toBeInstanceOf(Ticket::class);
    expect($query->getQuery()->from)->toBe('sao_tickets');
    expect($query->count())->toBe(3);
});
