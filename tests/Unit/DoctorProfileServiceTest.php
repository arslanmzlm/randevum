<?php

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use App\Modules\Core\Repositories\DoctorRepository;
use App\Modules\Core\Services\DoctorProfileService;
use App\Support\ClinicContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

// ---------------------------------------------------------------------------
// createOwnProfile — duplicate guard
// ---------------------------------------------------------------------------

test('createOwnProfile creates a doctor row with the user id', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $user = User::factory()->create();
    $service = app(DoctorProfileService::class);

    $doctor = $service->createOwnProfile($user);

    expect($doctor)->toBeInstanceOf(Doctor::class)
        ->and($doctor->user_id)->toBe($user->id)
        ->and($doctor->clinic_id)->toBe($clinic->id);
});

test('createOwnProfile throws ValidationException when user already has a profile', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $user = User::factory()->create();
    Doctor::factory()->create(['user_id' => $user->id, 'clinic_id' => $clinic->id]);

    $service = app(DoctorProfileService::class);

    expect(fn () => $service->createOwnProfile($user))
        ->toThrow(ValidationException::class);
});

test('createOwnProfile duplicate guard is global — catches profile at a different clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $user = User::factory()->create();

    // Profile exists at clinic A
    Doctor::factory()->create(['user_id' => $user->id, 'clinic_id' => $clinicA->id]);

    // Attempt to create at clinic B — DB UNIQUE on user_id must block it
    app(ClinicContext::class)->set($clinicB->id);
    $service = app(DoctorProfileService::class);

    expect(fn () => $service->createOwnProfile($user))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// update — is_active strip rule
// ---------------------------------------------------------------------------

test('update preserves is_active when canManage is true', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'is_active' => true]);
    $service = app(DoctorProfileService::class);

    $service->update($doctor, ['specialization' => 'Podoloji', 'is_active' => false], canManage: true);

    expect($doctor->fresh()->is_active)->toBeFalse();
});

test('update strips is_active when canManage is false', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'is_active' => true]);
    $service = app(DoctorProfileService::class);

    $service->update($doctor, ['specialization' => 'Podoloji', 'is_active' => false], canManage: false);

    // is_active must stay true — stripped from the payload
    expect($doctor->fresh()->is_active)->toBeTrue();
});

test('update still applies all other fields when canManage is false', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'title' => null,
        'certificate' => null,
        'specialization' => null,
    ]);
    $service = app(DoctorProfileService::class);

    $service->update($doctor, [
        'title' => 'Dr.',
        'certificate' => 'APMA',
        'specialization' => 'Podoloji',
        'is_active' => false,
    ], canManage: false);

    $fresh = $doctor->fresh();
    expect($fresh->title)->toBe('Dr.')
        ->and($fresh->certificate)->toBe('APMA')
        ->and($fresh->specialization)->toBe('Podoloji')
        ->and($fresh->is_active)->toBeTrue(); // stripped
});

// ---------------------------------------------------------------------------
// update — name propagation
// ---------------------------------------------------------------------------

test('update propagates first_name and last_name to the linked user', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $user = User::factory()->create(['first_name' => 'Old', 'last_name' => 'Name']);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $user->id]);
    $service = app(DoctorProfileService::class);

    $service->update($doctor, [
        'first_name' => 'New',
        'last_name' => 'Name',
        'specialization' => 'Podoloji',
    ], canManage: false);

    $freshUser = $user->fresh();
    expect($freshUser->first_name)->toBe('New')
        ->and($freshUser->last_name)->toBe('Name');
});

test('update does not touch user name when first_name and last_name are absent from payload', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $user = User::factory()->create(['first_name' => 'Stays', 'last_name' => 'Same']);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $user->id]);
    $service = app(DoctorProfileService::class);

    $service->update($doctor, ['specialization' => 'Podoloji'], canManage: false);

    $freshUser = $user->fresh();
    expect($freshUser->first_name)->toBe('Stays')
        ->and($freshUser->last_name)->toBe('Same');
});

// ---------------------------------------------------------------------------
// addDoctor — user + role + profile
// ---------------------------------------------------------------------------

test('addDoctor creates a new user and a doctor profile in the active clinic', function (): void {
    $this->seed(RoleSeeder::class);

    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);

    $service = app(DoctorProfileService::class);

    $doctor = $service->addDoctor([
        'first_name' => 'New',
        'last_name' => 'Doctor',
        'email' => 'new.doctor@unit.test',
        'password' => 'password',
        'title' => 'Dr.',
        'specialization' => 'Podoloji',
        'is_active' => true,
    ]);

    expect($doctor)->toBeInstanceOf(Doctor::class)
        ->and($doctor->clinic_id)->toBe($clinic->id);

    $newUser = User::where('email', 'new.doctor@unit.test')->first();
    expect($newUser)->not->toBeNull()
        ->and($newUser->first_name)->toBe('New')
        ->and($newUser->email_verified_at)->not->toBeNull();
});

test('addDoctor assigns the clinic-scoped doctor role to the new user', function (): void {
    $this->seed(RoleSeeder::class);

    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);

    $service = app(DoctorProfileService::class);

    $service->addDoctor([
        'first_name' => 'Role',
        'last_name' => 'Test',
        'email' => 'role.test@unit.test',
        'password' => 'password',
        'is_active' => true,
    ]);

    $newUser = User::where('email', 'role.test@unit.test')->first();
    expect($newUser)->not->toBeNull();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    expect($newUser->fresh()->hasRole('doctor'))->toBeTrue();
});

test('addDoctor is atomic — rolls back user creation on profile failure', function (): void {
    $this->seed(RoleSeeder::class);

    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);

    // Force a failure in the repository so the transaction rolls back after user creation
    $this->mock(DoctorRepository::class, function ($mock) {
        $mock->shouldReceive('create')->andThrow(new RuntimeException('forced failure'));
    });

    $service = app(DoctorProfileService::class);

    expect(fn () => $service->addDoctor([
        'first_name' => 'Rollback',
        'last_name' => 'Test',
        'email' => 'rollback@unit.test',
        'password' => 'password',
        'is_active' => true,
    ]))->toThrow(RuntimeException::class);

    // User must NOT persist (transaction rolled back)
    expect(User::where('email', 'rollback@unit.test')->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// remove — role revoke + soft-delete
// ---------------------------------------------------------------------------

test('remove soft-deletes the doctor record', function (): void {
    $this->seed(RoleSeeder::class);

    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);

    $user = User::factory()->create();
    $user->assignRole('doctor');
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $user->id]);

    $service = app(DoctorProfileService::class);
    $service->remove($doctor);

    expect(Doctor::withoutGlobalScopes()->find($doctor->id)->deleted_at)->not->toBeNull();
});

test('remove revokes the clinic-scoped doctor role from the user', function (): void {
    $this->seed(RoleSeeder::class);

    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);

    $user = User::factory()->create();
    $user->assignRole('doctor');
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $user->id]);

    $service = app(DoctorProfileService::class);
    $service->remove($doctor);

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    expect($user->fresh()->hasRole('doctor'))->toBeFalse();
});
