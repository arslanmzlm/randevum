<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionSeeder extends Seeder
{
    /**
     * Permission => the baseline roles that hold it. Only the abilities the app
     * actually enforces today; this grows feature by feature, not as a full
     * up-front matrix (permission-management UI is Faz 3).
     *
     * @var array<string, list<string>>
     */
    private const PERMISSIONS = [
        'clinic.update' => ['owner'],
        'doctors.viewAny' => ['owner', 'manager', 'doctor', 'receptionist', 'assistant'],
        'doctors.create' => ['owner', 'manager'],
        'doctors.update' => ['owner', 'manager'],
        'doctors.delete' => ['owner', 'manager'],
        'doctors.createOwn' => ['owner'],
        'services.viewAny' => ['owner', 'manager', 'doctor'],
        'services.create' => ['owner', 'manager'],
        'services.update' => ['owner', 'manager'],
        'services.delete' => ['owner', 'manager'],
        'products.viewAny' => ['owner', 'manager', 'doctor'],
        'products.create' => ['owner', 'manager'],
        'products.update' => ['owner', 'manager'],
        'products.delete' => ['owner', 'manager'],
        'products.manageStock' => ['owner', 'manager'],
    ];

    public function run(): void
    {
        // Permissions and roles are global records (clinic_id null) — only the
        // role↔user assignment is clinic-scoped. Pin team context to null so the
        // permission/role rows resolve to the global ones.
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $permissionsByRole = [];

        foreach (self::PERMISSIONS as $name => $roles) {
            Permission::findOrCreate($name, 'web');

            foreach ($roles as $role) {
                $permissionsByRole[$role][] = $name;
            }
        }

        // syncPermissions is authoritative — re-running heals any drift.
        foreach ($permissionsByRole as $role => $permissions) {
            Role::findByName($role, 'web')->syncPermissions($permissions);
        }
    }
}
