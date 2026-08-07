<?php

use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Services\RoleCustomizationService;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

function slRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

function slPermissionNames(int $roleId): array
{
    return DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_has_permissions.role_id', $roleId)
        ->pluck('permissions.name')
        ->all();
}

it('refuses to drop roles.manage from the owner column the acting owner holds', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    slRole($owner, 'owner', $clinic->id);

    $globalOwner = Role::query()->where('name', 'owner')->whereNull('clinic_id')->firstOrFail();
    $before = slPermissionNames($globalOwner->id);
    $desired = array_values(array_diff($before, ['roles.manage']));

    $this->actingAs($owner)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [['id' => $globalOwner->id, 'permissions' => $desired]],
        ])
        ->assertInvalid(['permissions']);

    expect(slPermissionNames($globalOwner->id))->toEqualCanonicalizing($before)
        ->and(Role::query()->where('name', 'owner')->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});

it('refuses to drop roles.viewAny from the owner column the acting owner holds', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    slRole($owner, 'owner', $clinic->id);

    $globalOwner = Role::query()->where('name', 'owner')->whereNull('clinic_id')->firstOrFail();
    $before = slPermissionNames($globalOwner->id);
    $desired = array_values(array_diff($before, ['roles.viewAny']));

    $this->actingAs($owner)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [['id' => $globalOwner->id, 'permissions' => $desired]],
        ])
        ->assertInvalid(['permissions']);

    expect(slPermissionNames($globalOwner->id))->toEqualCanonicalizing($before);
});

it('allows dropping roles.manage from a role the acting owner does not hold', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    slRole($owner, 'owner', $clinic->id);

    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $before = slPermissionNames($globalManager->id);
    $desired = array_values(array_diff($before, ['roles.manage']));

    $this->actingAs($owner)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [['id' => $globalManager->id, 'permissions' => $desired]],
        ])
        ->assertRedirect();

    $copy = Role::query()->where('name', 'manager')->where('clinic_id', $clinic->id)->firstOrFail();
    expect(slPermissionNames($copy->id))->not->toContain('roles.manage');
});

it('cannot delete a custom role the acting owner holds themselves', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    slRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $customRole = Role::query()->where('clinic_id', $clinic->id)->where('name', 'Muhasebeci')->firstOrFail();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole($customRole);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $owner->unsetRelation('roles')->unsetRelation('permissions');

    $this->actingAs($owner)
        ->delete(route('settings.roles.destroy', $customRole))
        ->assertInvalid(['role']);

    expect(Role::query()->whereKey($customRole->id)->exists())->toBeTrue();
});

it("refuses revert when it would strip a protected permission from the acting user's own role", function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    slRole($owner, 'owner', $clinic->id);

    app(ClinicContext::class)->set($clinic->id);
    $globalOwner = Role::query()->where('name', 'owner')->whereNull('clinic_id')->firstOrFail();
    $copy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalOwner);
    app(ClinicContext::class)->forget();

    // Simulate template drift: the global row no longer carries roles.manage while the
    // clinic's existing copy still does — exactly the condition assertRevertKeepsProtected
    // exists to catch (revert must never silently lock the acting user out).
    $rolesManageId = Permission::query()->where('name', 'roles.manage')->where('guard_name', 'web')->value('id');
    DB::table('role_has_permissions')->where('role_id', $globalOwner->id)->where('permission_id', $rolesManageId)->delete();

    $this->actingAs($owner)
        ->delete(route('settings.roles.revert'))
        ->assertInvalid(['role']);

    expect(Role::query()->whereKey($copy->id)->exists())->toBeTrue();
});
