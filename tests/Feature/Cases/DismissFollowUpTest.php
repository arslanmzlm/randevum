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
 * Assign a clinic-scoped role (dismiss follow-up tests).
 */
function dfuRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic with owner, a doctor, a patient, and a case with an overdue follow-up.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, case: CaseRecord}
 */
function dfuSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    dfuRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $case = CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => today()->format('Y-m-d'),
        'follow_up_note' => 'Call the patient',
    ]);

    return compact('clinic', 'owner', 'doctor', 'patient', 'case');
}

// ---------------------------------------------------------------------------
// Authorization — who can dismiss
// ---------------------------------------------------------------------------

it('owner can dismiss a follow-up and both fields are nulled', function (): void {
    ['owner' => $owner, 'case' => $case] = dfuSetup();

    $this->actingAs($owner)
        ->delete(route('cases.follow-up.dismiss', $case))
        ->assertRedirect();

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->follow_up_date)->toBeNull()
        ->and($fresh->follow_up_note)->toBeNull();
});

it('manager can dismiss a follow-up', function (): void {
    ['clinic' => $clinic, 'case' => $case] = dfuSetup();
    $manager = User::factory()->create();
    dfuRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->delete(route('cases.follow-up.dismiss', $case))
        ->assertRedirect();

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->follow_up_date)->toBeNull()
        ->and($fresh->follow_up_note)->toBeNull();
});

it('receptionist (no cases.update) can dismiss with only followUps.dismiss', function (): void {
    ['clinic' => $clinic, 'case' => $case] = dfuSetup();
    $receptionist = User::factory()->create();
    dfuRole($receptionist, 'receptionist', $clinic->id);

    // Confirm the receptionist does NOT hold cases.update before testing the dismiss.
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $lacksUpdate = ! $receptionist->can('cases.update');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    expect($lacksUpdate)->toBeTrue('receptionist must not have cases.update so this test proves it is not required');

    $this->actingAs($receptionist)
        ->delete(route('cases.follow-up.dismiss', $case))
        ->assertRedirect();

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->follow_up_date)->toBeNull()
        ->and($fresh->follow_up_note)->toBeNull();
});

it('doctor (lacks followUps.dismiss) gets 403', function (): void {
    ['clinic' => $clinic, 'case' => $case] = dfuSetup();
    $doctorUser = User::factory()->create();
    dfuRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->delete(route('cases.follow-up.dismiss', $case))
        ->assertForbidden();

    // Data must remain untouched
    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->follow_up_date)->not->toBeNull()
        ->and($fresh->follow_up_note)->toBe('Call the patient');
});

it('assistant (lacks followUps.dismiss) gets 403', function (): void {
    ['clinic' => $clinic, 'case' => $case] = dfuSetup();
    $assistant = User::factory()->create();
    dfuRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->delete(route('cases.follow-up.dismiss', $case))
        ->assertForbidden();
});

it('unauthenticated user is redirected to login', function (): void {
    ['case' => $case] = dfuSetup();

    $this->delete(route('cases.follow-up.dismiss', $case))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Business logic — cleared state regardless of case status
// ---------------------------------------------------------------------------

it('dismiss clears both follow_up_date and follow_up_note', function (): void {
    ['owner' => $owner, 'case' => $case] = dfuSetup();
    expect($case->follow_up_note)->toBe('Call the patient');

    $this->actingAs($owner)
        ->delete(route('cases.follow-up.dismiss', $case))
        ->assertRedirect();

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->follow_up_date)->toBeNull()
        ->and($fresh->follow_up_note)->toBeNull();
});

it('dismiss works on a closed case', function (): void {
    ['owner' => $owner, 'case' => $case] = dfuSetup();
    $case->updateQuietly(['status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($owner)
        ->delete(route('cases.follow-up.dismiss', $case))
        ->assertRedirect();

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->follow_up_date)->toBeNull()
        ->and($fresh->follow_up_note)->toBeNull();
});

it('dismiss works on a follow_up-status case', function (): void {
    ['owner' => $owner, 'case' => $case] = dfuSetup();
    $case->updateQuietly(['status' => 'follow_up']);

    $this->actingAs($owner)
        ->delete(route('cases.follow-up.dismiss', $case))
        ->assertRedirect();

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->follow_up_date)->toBeNull()
        ->and($fresh->follow_up_note)->toBeNull();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it("clinic A user gets 404 trying to dismiss clinic B's case follow-up", function (): void {
    $setupA = dfuSetup();
    $setupB = dfuSetup();

    $this->actingAs($setupA['owner'])
        ->delete(route('cases.follow-up.dismiss', $setupB['case']))
        ->assertNotFound();

    // Clinic B's data must be untouched
    $fresh = CaseRecord::withoutGlobalScopes()->find($setupB['case']->id);
    expect($fresh->follow_up_date)->not->toBeNull()
        ->and($fresh->follow_up_note)->toBe('Call the patient');
});
