<?php

namespace Database\Seeders;

use App\Domain\Access\Permission;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role as RoleModel;
use Spatie\Permission\PermissionRegistrar;

/** Roles and permissions from docs/04-roles-and-access.md. Safe to re-run. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ((new \ReflectionClass(Permission::class))->getConstants() as $name) {
            PermissionModel::findOrCreate($name, 'web');
        }

        foreach (Permission::matrix() as $role => $permissions) {
            RoleModel::findOrCreate($role, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
