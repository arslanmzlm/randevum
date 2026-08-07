<?php

namespace Database\Seeders;

use App\Enums\ClinicRole;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class RoleSeeder extends Seeder
{
    /**
     * Platform-global roles (faz-0), clinic_id always null — never customizable, never a
     * matrix column. Clinic-scoped baseline roles come from ClinicRole::values().
     *
     * @var list<string>
     */
    private const GLOBAL_ROLES = [
        'superadmin',
        'admin',
        'moderator',
        'patient',
    ];

    public function run(): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        foreach ([...self::GLOBAL_ROLES, ...ClinicRole::values()] as $role) {
            // Explicit global find-or-create (never Role::findOrCreate, which could match a
            // clinic's copy of the same name) so a clinic customization is never mistaken
            // for — or overwritten as — the baseline.
            Role::query()->where('name', $role)->where('guard_name', 'web')->whereNull('clinic_id')->first()
                ?? Role::create(['name' => $role, 'guard_name' => 'web', 'clinic_id' => null]);
        }
    }
}
