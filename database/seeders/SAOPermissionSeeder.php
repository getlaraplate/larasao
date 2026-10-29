<?php

declare(strict_types=1);

namespace Modules\SAO\Database\Seeders;

use Modules\Core\Authorization\PermissionManifest;
use Modules\Core\Models\Permission;
use Modules\Core\Overrides\Seeder;

/**
 * Materializes this module's slice of the permission manifest.
 *
 * The names are declared once in {@see \Modules\SAO\Authorization\SAOPermissions}
 * and created by `permission:refresh`, which runs before every module seeder in
 * the graph. This seeder keeps SAO seeded in isolation self-sufficient, which is
 * what the module test suites rely on. The seed graph runs it as a node of its own, so
 * {@see SAODatabaseSeeder} does not call it.
 *
 * Only the domain verbs come through here. The CRUD ones (`select`, `insert`,
 * `update`, `delete` and the rest) belong to `permission:refresh`, which
 * generates them for every table it manages.
 */
final class SAOPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission_model = new Permission;

        if (! $permission_model->getConnection()->getSchemaBuilder()->hasTable($permission_model->getTable())) {
            return;
        }

        $this->logOperation(Permission::class);

        $names = app(PermissionManifest::class)->namesFor('SAO');
        $created = 0;

        foreach ($names as $name) {
            if ($permission_model->newQuery()->firstOrCreate(['name' => $name])->wasRecentlyCreated) {
                $created++;
            }
        }

        $this->logOutcome($created, 0, count($names) - $created);
    }
}
