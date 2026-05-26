<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Baseline roles (faz-0). Defined globally (clinic_id = null); clinic
     * scoping happens on assignment via the Teams clinic_id on model_has_roles.
     *
     * @var list<string>
     */
    private const ROLES = [
        'superadmin',
        'owner',
        'manager',
        'doctor',
        'receptionist',
        'assistant',
        'patient',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        foreach (self::ROLES as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
