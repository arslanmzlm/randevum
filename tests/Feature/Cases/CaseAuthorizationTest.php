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
 * Assign a clinic-scoped role (case-authorization tests).
 */
function cauRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic with owner, two doctors, a patient, and a case owned by doctor A.
 *
 * @return array{clinic: Clinic, owner: User, doctorUserA: User, doctorA: Doctor, doctorUserB: User, doctorB: Doctor, patient: Patient, case: CaseRecord}
 */
function cauSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cauRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    cauRole($doctorUserA, 'doctor', $clinic->id);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    cauRole($doctorUserB, 'doctor', $clinic->id);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctorA->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    return compact('clinic', 'owner', 'doctorUserA', 'doctorA', 'doctorUserB', 'doctorB', 'patient', 'case');
}

// ---------------------------------------------------------------------------
// cases.viewAny — who can hit the index
// ---------------------------------------------------------------------------

it('receptionist (no cases.viewAny) gets 403 on case index', function (): void {
    ['clinic' => $clinic] = cauSetup();
    $receptionist = User::factory()->create();
    cauRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('cases.index'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// cases.view (viewAll || ownsCase) — who can open the show page
// ---------------------------------------------------------------------------

it('doctor A can view their own case (own-record path)', function (): void {
    ['doctorUserA' => $doctorUserA, 'case' => $case] = cauSetup();

    $this->actingAs($doctorUserA)
        ->get(route('cases.show', $case))
        ->assertOk();
});

it("doctor B gets 403 on doctor A's case (lacks viewAll, not owner)", function (): void {
    ['doctorUserB' => $doctorUserB, 'case' => $case] = cauSetup();

    $this->actingAs($doctorUserB)
        ->get(route('cases.show', $case))
        ->assertForbidden();
});

it('owner can view any case in the clinic (has viewAll)', function (): void {
    ['owner' => $owner, 'case' => $case] = cauSetup();

    $this->actingAs($owner)
        ->get(route('cases.show', $case))
        ->assertOk();
});

it('manager can view any case in the clinic (has viewAll)', function (): void {
    ['clinic' => $clinic, 'case' => $case] = cauSetup();
    $manager = User::factory()->create();
    cauRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('cases.show', $case))
        ->assertOk();
});

it('assistant can view any case in the clinic (has viewAll, no update)', function (): void {
    ['clinic' => $clinic, 'case' => $case] = cauSetup();
    $assistant = User::factory()->create();
    cauRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('cases.show', $case))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// cases.update (cases.update && (viewAll || ownsCase)) — who can mutate
// ---------------------------------------------------------------------------

it('doctor A can update notes on their own case', function (): void {
    ['doctorUserA' => $doctorUserA, 'case' => $case] = cauSetup();

    $this->actingAs($doctorUserA)
        ->patch(route('cases.notes.update', $case), ['notes' => 'Doctor own note'])
        ->assertRedirect(route('cases.show', $case));
});

it("doctor B gets 403 updating notes on doctor A's case (lacks viewAll and not owner)", function (): void {
    ['doctorUserB' => $doctorUserB, 'case' => $case] = cauSetup();

    $this->actingAs($doctorUserB)
        ->patch(route('cases.notes.update', $case), ['notes' => 'Unauthorized note'])
        ->assertForbidden();
});

it('owner can update notes on any case (has cases.update + viewAll)', function (): void {
    ['owner' => $owner, 'case' => $case] = cauSetup();

    $this->actingAs($owner)
        ->patch(route('cases.notes.update', $case), ['notes' => 'Owner note'])
        ->assertRedirect(route('cases.show', $case));
});

it('manager can update notes on any case (has cases.update + viewAll)', function (): void {
    ['clinic' => $clinic, 'case' => $case] = cauSetup();
    $manager = User::factory()->create();
    cauRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->patch(route('cases.notes.update', $case), ['notes' => 'Manager note'])
        ->assertRedirect(route('cases.show', $case));
});

it('assistant gets 403 updating notes (has viewAll but lacks cases.update)', function (): void {
    ['clinic' => $clinic, 'case' => $case] = cauSetup();
    $assistant = User::factory()->create();
    cauRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->patch(route('cases.notes.update', $case), ['notes' => 'Assistant note'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// cases.create — who can create a case
// ---------------------------------------------------------------------------

it('doctor A can create a case via POST /cases', function (): void {
    ['clinic' => $clinic, 'doctorUserA' => $doctorUserA, 'doctorA' => $doctorA, 'patient' => $patient] = cauSetup();

    $this->actingAs($doctorUserA)
        ->post(route('cases.store'), [
            'patient_id' => $patient->id,
            'title' => 'Doctor Creates Case',
            'doctor_id' => $doctorA->id,
        ])
        ->assertRedirect();
});

it('receptionist gets 403 on POST /cases (lacks cases.create)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = cauSetup();
    $receptionist = User::factory()->create();
    cauRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('cases.store'), [
            'patient_id' => $patient->id,
            'title' => 'Unauthorized Case',
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Status change authorization mirrors notes update (same update policy)
// ---------------------------------------------------------------------------

it("doctor B gets 403 changing status on doctor A's case", function (): void {
    ['doctorUserB' => $doctorUserB, 'case' => $case] = cauSetup();

    $this->actingAs($doctorUserB)
        ->patch(route('cases.status.update', $case), ['status' => 'closed'])
        ->assertForbidden();
});
