<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('grants the owner role the full clinic + doctor + service permission set', function (): void {
    $owner = Role::findByName('owner', 'web');

    expect($owner->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            'clinic.update',
            'doctors.viewAny',
            'doctors.create',
            'doctors.update',
            'doctors.delete',
            'doctors.createOwn',
            'services.viewAny',
            'services.create',
            'services.update',
            'services.delete',
        ]);
});

it('grants the manager role doctor and service management but not clinic or self-create', function (): void {
    $manager = Role::findByName('manager', 'web');

    expect($manager->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            'doctors.viewAny',
            'doctors.create',
            'doctors.update',
            'doctors.delete',
            'services.viewAny',
            'services.create',
            'services.update',
            'services.delete',
        ]);
});

it('grants the doctor role read-only access to doctors and services', function (): void {
    expect(Role::findByName('doctor', 'web')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing(['doctors.viewAny', 'services.viewAny']);
});

it('grants receptionist and assistant read-only access to the doctor list only', function (string $role): void {
    expect(Role::findByName($role, 'web')->permissions->pluck('name')->all())
        ->toEqual(['doctors.viewAny']);
})->with(['receptionist', 'assistant']);

it('leaves global roles without clinic-scoped permissions', function (string $role): void {
    expect(Role::findByName($role, 'web')->permissions)->toBeEmpty();
})->with(['superadmin', 'admin', 'moderator', 'patient']);

it('scopes a permission to the clinic where the role was assigned', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $user = User::factory()->create();

    $registrar = app(PermissionRegistrar::class);
    $registrar->setPermissionsTeamId($clinicA->id);
    $user->assignRole('owner');

    // Owner at clinic A → has the permission there.
    $registrar->setPermissionsTeamId($clinicA->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    expect($user->can('clinic.update'))->toBeTrue();

    // …but not when the active clinic is B (no role assignment there).
    $registrar->setPermissionsTeamId($clinicB->id);
    $user->unsetRelation('roles')->unsetRelation('permissions');
    expect($user->can('clinic.update'))->toBeFalse();
});
