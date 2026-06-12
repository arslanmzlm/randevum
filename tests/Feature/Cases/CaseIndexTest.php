<?php

use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role (case-index tests).
 */
function cixRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic with an owner, two doctors with one case each, and a patient.
 *
 * @return array{clinic: Clinic, owner: User, doctorUserA: User, doctorA: Doctor, doctorUserB: User, doctorB: Doctor, patient: Patient, caseA: CaseRecord, caseB: CaseRecord}
 */
function cixSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cixRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    cixRole($doctorUserA, 'doctor', $clinic->id);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    cixRole($doctorUserB, 'doctor', $clinic->id);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $caseA = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctorA->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $caseB = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctorB->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    return compact('clinic', 'owner', 'doctorUserA', 'doctorA', 'doctorUserB', 'doctorB', 'patient', 'caseA', 'caseB');
}

// ---------------------------------------------------------------------------
// View-all: owner and manager see every case in the clinic
// ---------------------------------------------------------------------------

it('renders the cases/Index page for an owner', function (): void {
    ['owner' => $owner] = cixSetup();

    $this->actingAs($owner)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('cases/Index'));
});

it('owner with viewAll sees all cases in the clinic (both doctors)', function (): void {
    ['owner' => $owner] = cixSetup();

    $this->actingAs($owner)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('cases.data', 2));
});

it('manager with viewAll sees all cases in the clinic', function (): void {
    ['clinic' => $clinic] = cixSetup();

    $manager = User::factory()->create();
    cixRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('cases.data', 2));
});

it('assistant with viewAll sees all cases in the clinic', function (): void {
    ['clinic' => $clinic] = cixSetup();

    $assistant = User::factory()->create();
    cixRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('cases.data', 2));
});

// ---------------------------------------------------------------------------
// Own-only scoping: doctor sees only their own cases
// ---------------------------------------------------------------------------

it('doctor sees only their own cases', function (): void {
    ['doctorUserA' => $doctorUserA, 'caseA' => $caseA] = cixSetup();

    $this->actingAs($doctorUserA)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('cases.data', 1)
            ->where('cases.data.0.id', $caseA->id)
        );
});

it("case list for doctor B does not include doctor A's case", function (): void {
    ['doctorUserB' => $doctorUserB, 'caseA' => $caseA] = cixSetup();

    $this->actingAs($doctorUserB)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('cases.data', 1)
            ->where('cases.data.0.id', fn ($id) => $id !== $caseA->id)
        );
});

// ---------------------------------------------------------------------------
// No doctor profile → empty list (user has viewAny but not viewAll)
// ---------------------------------------------------------------------------

it('doctor-role user with no Doctor profile row gets an empty case list', function (): void {
    ['clinic' => $clinic] = cixSetup();

    $noProfileUser = User::factory()->create();
    cixRole($noProfileUser, 'doctor', $clinic->id);

    $this->actingAs($noProfileUser)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('cases.data', 0));
});

// ---------------------------------------------------------------------------
// Authorization: roles without cases.viewAny get 403
// ---------------------------------------------------------------------------

it('receptionist gets 403 on the case index (no cases.viewAny)', function (): void {
    ['clinic' => $clinic] = cixSetup();

    $receptionist = User::factory()->create();
    cixRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('cases.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Prop contracts
// ---------------------------------------------------------------------------

it('index passes doctors list to viewAll user (owner)', function (): void {
    ['owner' => $owner] = cixSetup();

    $this->actingAs($owner)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('doctors'));
});

it('index passes ownDoctorId matching the authenticated doctor profile', function (): void {
    ['doctorUserA' => $doctorUserA, 'doctorA' => $doctorA] = cixSetup();

    $this->actingAs($doctorUserA)
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('ownDoctorId', $doctorA->id));
});
