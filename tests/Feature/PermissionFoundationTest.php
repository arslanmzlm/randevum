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

it('grants the owner role the full clinic + doctor + service + product + patient + availability permission set', function (): void {
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
            'products.viewAny',
            'products.create',
            'products.update',
            'products.delete',
            'products.manageStock',
            'patients.viewAny',
            'patients.view',
            'patients.create',
            'patients.update',
            'patients.delete',
            'patients.note.update',
            'scheduleExceptions.viewAny',
            'scheduleExceptions.manage',
            'appointments.viewAny',
            'appointments.viewAll',
            'appointments.create',
            'appointments.assignDoctor',
            'appointments.update',
            'appointments.cancel',
            'appointments.delete',
            'appointments.bulkCancel',
            'appointmentTypes.viewAny',
            'appointmentTypes.create',
            'appointmentTypes.update',
            'appointmentTypes.delete',
        ]);
});

it('grants the manager role full management except clinic and self-create', function (): void {
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
            'products.viewAny',
            'products.create',
            'products.update',
            'products.delete',
            'products.manageStock',
            'patients.viewAny',
            'patients.view',
            'patients.create',
            'patients.update',
            'patients.delete',
            'patients.note.update',
            'scheduleExceptions.viewAny',
            'scheduleExceptions.manage',
            'appointments.viewAny',
            'appointments.viewAll',
            'appointments.create',
            'appointments.assignDoctor',
            'appointments.update',
            'appointments.cancel',
            'appointments.delete',
            'appointments.bulkCancel',
            'appointmentTypes.viewAny',
            'appointmentTypes.create',
            'appointmentTypes.update',
            'appointmentTypes.delete',
        ]);
});

it('grants the doctor role read access to catalog and full patient management', function (): void {
    expect(Role::findByName('doctor', 'web')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            'doctors.viewAny',
            'services.viewAny',
            'products.viewAny',
            'patients.viewAny',
            'patients.view',
            'patients.create',
            'patients.update',
            'patients.delete',
            'patients.note.update',
            'scheduleExceptions.viewAny',
            'appointments.viewAny',
            'appointments.create',
            'appointments.update',
            'appointments.cancel',
            'appointmentTypes.viewAny',
        ]);
});

it('grants receptionist full patient management and doctor list access', function (): void {
    expect(Role::findByName('receptionist', 'web')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            'doctors.viewAny',
            'patients.viewAny',
            'patients.view',
            'patients.create',
            'patients.update',
            'patients.delete',
            'patients.note.update',
            'scheduleExceptions.viewAny',
            'scheduleExceptions.manage',
            'appointments.viewAny',
            'appointments.viewAll',
            'appointments.create',
            'appointments.assignDoctor',
            'appointments.update',
            'appointments.cancel',
        ]);
});

it('grants assistant read-only access to doctors, patients, and availability', function (): void {
    expect(Role::findByName('assistant', 'web')->permissions->pluck('name')->all())
        ->toEqualCanonicalizing([
            'doctors.viewAny',
            'patients.viewAny',
            'patients.view',
            'scheduleExceptions.viewAny',
            'appointments.viewAny',
            'appointments.viewAll',
        ]);
});

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
