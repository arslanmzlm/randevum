<?php

use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

function nupoRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('never writes a per-user permission override across a full create/grant/revert/delete cycle', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    nupoRole($owner, 'owner', $clinic->id);

    expect(DB::table('model_has_permissions')->count())->toBe(0);

    // Create a custom role.
    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $customRole = Role::query()->where('clinic_id', $clinic->id)->where('name', 'Muhasebeci')->firstOrFail();

    // Grant it a permission, and copy-on-write the manager baseline column too.
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $managerPermissions = DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_has_permissions.role_id', $globalManager->id)
        ->pluck('permissions.name')
        ->all();

    $this->actingAs($owner)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [
                ['id' => $customRole->id, 'permissions' => ['services.create']],
                ['id' => $globalManager->id, 'permissions' => [...$managerPermissions, 'doctors.createOwn']],
            ],
        ])
        ->assertRedirect();

    expect(DB::table('model_has_permissions')->count())->toBe(0);

    // Revert the baseline customization.
    $this->actingAs($owner)->delete(route('settings.roles.revert'))->assertRedirect();

    expect(DB::table('model_has_permissions')->count())->toBe(0);

    // Delete the (still-unassigned) custom role.
    $this->actingAs($owner)
        ->delete(route('settings.roles.destroy', $customRole))
        ->assertRedirect();

    expect(DB::table('model_has_permissions')->count())->toBe(0);
});
