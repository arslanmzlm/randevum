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

function rpuRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

function rpuGlobalRole(string $name): Role
{
    return Role::query()->where('name', $name)->where('guard_name', 'web')->whereNull('clinic_id')->firstOrFail();
}

function rpuPermissionNames(int $roleId): array
{
    return DB::table('role_has_permissions')
        ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
        ->where('role_has_permissions.role_id', $roleId)
        ->pluck('permissions.name')
        ->all();
}

it('copy-on-writes a baseline column on first edit, adding the new permission, without touching the global row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    rpuRole($owner, 'owner', $clinic->id);
    rpuRole($doctorUser, 'doctor', $clinic->id);

    $globalDoctor = rpuGlobalRole('doctor');
    $globalPermissions = rpuPermissionNames($globalDoctor->id);
    $desired = array_values(array_unique([...$globalPermissions, 'services.create']));

    $this->actingAs($owner)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [['id' => $globalDoctor->id, 'permissions' => $desired]],
        ])
        ->assertRedirect(route('settings.roles.index'));

    // Global row untouched.
    expect(rpuPermissionNames($globalDoctor->id))->toEqualCanonicalizing($globalPermissions);

    $copy = Role::query()->where('name', 'doctor')->where('clinic_id', $clinic->id)->first();
    expect($copy)->not->toBeNull()
        ->and($copy->id)->not->toBe($globalDoctor->id)
        ->and(rpuPermissionNames($copy->id))->toEqualCanonicalizing($desired);

    $doctorAssignment = DB::table('model_has_roles')
        ->where('model_id', $doctorUser->id)->where('model_type', $doctorUser->getMorphClass())
        ->where('clinic_id', $clinic->id)->value('role_id');
    expect($doctorAssignment)->toBe($copy->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $doctorUser->unsetRelation('roles')->unsetRelation('permissions');
    expect($doctorUser->can('services.create'))->toBeTrue();
});

it('updates the existing copy in place on a second save — no second row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rpuRole($owner, 'owner', $clinic->id);

    $globalDoctor = rpuGlobalRole('doctor');
    $globalPermissions = rpuPermissionNames($globalDoctor->id);

    $this->actingAs($owner)->put(route('settings.roles.permissions.update'), [
        'roles' => [['id' => $globalDoctor->id, 'permissions' => [...$globalPermissions, 'services.create']]],
    ])->assertRedirect();

    $copy = Role::query()->where('name', 'doctor')->where('clinic_id', $clinic->id)->firstOrFail();

    $this->actingAs($owner)->put(route('settings.roles.permissions.update'), [
        'roles' => [['id' => $copy->id, 'permissions' => [...$globalPermissions, 'services.create', 'clinic.update']]],
    ])->assertRedirect();

    $copiesCount = Role::query()->where('name', 'doctor')->where('clinic_id', $clinic->id)->count();
    $copyAgain = Role::query()->where('name', 'doctor')->where('clinic_id', $clinic->id)->firstOrFail();

    expect($copiesCount)->toBe(1)
        ->and($copyAgain->id)->toBe($copy->id)
        ->and(rpuPermissionNames($copy->id))->toContain('clinic.update');
});

it('does not mint a copy for a submitted role whose permission set is unchanged', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rpuRole($owner, 'owner', $clinic->id);

    $globalDoctor = rpuGlobalRole('doctor');
    $globalPermissions = rpuPermissionNames($globalDoctor->id);

    $this->actingAs($owner)->put(route('settings.roles.permissions.update'), [
        'roles' => [['id' => $globalDoctor->id, 'permissions' => $globalPermissions]],
    ])->assertRedirect();

    expect(Role::query()->where('name', 'doctor')->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});

it('422s for a duplicate role id in the bulk-save payload', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rpuRole($owner, 'owner', $clinic->id);

    $globalDoctor = rpuGlobalRole('doctor');
    $permissions = rpuPermissionNames($globalDoctor->id);

    $this->actingAs($owner)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [
                ['id' => $globalDoctor->id, 'permissions' => $permissions],
                ['id' => $globalDoctor->id, 'permissions' => $permissions],
            ],
        ])
        ->assertInvalid(['roles.0.id', 'roles.1.id']);

    expect(Role::query()->where('name', 'doctor')->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});

it('403s a bulk save without roles.manage', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    rpuRole($receptionist, 'receptionist', $clinic->id);

    $globalDoctor = rpuGlobalRole('doctor');

    $this->actingAs($receptionist)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [['id' => $globalDoctor->id, 'permissions' => rpuPermissionNames($globalDoctor->id)]],
        ])
        ->assertForbidden();
});

it('422s for an unknown permission name and writes nothing', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rpuRole($owner, 'owner', $clinic->id);

    $globalDoctor = rpuGlobalRole('doctor');
    $before = rpuPermissionNames($globalDoctor->id);

    $this->actingAs($owner)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [['id' => $globalDoctor->id, 'permissions' => [...$before, 'not.a.real.permission']]],
        ])
        ->assertInvalid(['roles.0.permissions.'.count($before)]);

    expect(Role::query()->where('name', 'doctor')->where('clinic_id', $clinic->id)->exists())->toBeFalse();
});
