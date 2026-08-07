<?php

use App\Enums\FollowUpStatus;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\StatusLog;
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
 * Assign a clinic-scoped role (follow-up complete/cancel tests).
 */
function fcpRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, followUp: FollowUp}
 */
function fcpSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    fcpRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $followUp = FollowUp::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
    ]);

    return compact('clinic', 'owner', 'doctor', 'patient', 'followUp');
}

// ---------------------------------------------------------------------------
// Complete — persists, is optional-note, and does not delete the row
// ---------------------------------------------------------------------------

it('owner completes an open follow-up', function (): void {
    ['owner' => $owner, 'followUp' => $followUp] = fcpSetup();

    $this->actingAs($owner)
        ->patch(route('follow-ups.complete', $followUp), ['result_note' => 'Reached the patient'])
        ->assertRedirect();

    $fresh = FollowUp::withoutGlobalScopes()->find($followUp->id);
    expect($fresh->status)->toBe(FollowUpStatus::Done)
        ->and($fresh->completed_at)->not->toBeNull()
        ->and($fresh->completed_by_user_id)->toBe($owner->id)
        ->and($fresh->result_note)->toBe('Reached the patient');
});

it('completes with no result_note (optional)', function (): void {
    ['owner' => $owner, 'followUp' => $followUp] = fcpSetup();

    $this->actingAs($owner)
        ->patch(route('follow-ups.complete', $followUp), [])
        ->assertRedirect();

    $fresh = FollowUp::withoutGlobalScopes()->find($followUp->id);
    expect($fresh->status)->toBe(FollowUpStatus::Done)
        ->and($fresh->result_note)->toBeNull();
});

it('the completed row is not deleted', function (): void {
    ['owner' => $owner, 'followUp' => $followUp] = fcpSetup();

    $this->actingAs($owner)->patch(route('follow-ups.complete', $followUp), []);

    expect(FollowUp::withoutGlobalScopes()->find($followUp->id))->not->toBeNull();
});

it('completing an already-done follow-up fails', function (): void {
    ['owner' => $owner, 'followUp' => $followUp] = fcpSetup();
    $followUp->updateQuietly(['status' => 'done', 'completed_at' => now()]);

    $this->actingAs($owner)
        ->patch(route('follow-ups.complete', $followUp), [])
        ->assertSessionHasErrors('status');
});

it('writes an open -> done status_log on complete', function (): void {
    ['owner' => $owner, 'followUp' => $followUp] = fcpSetup();

    $this->actingAs($owner)->patch(route('follow-ups.complete', $followUp), []);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'follow_up')
        ->where('loggable_id', $followUp->id)
        ->where('from_status', 'open')
        ->where('to_status', 'done')
        ->first();

    expect($log)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Cancel
// ---------------------------------------------------------------------------

it('owner cancels an open follow-up', function (): void {
    ['owner' => $owner, 'followUp' => $followUp] = fcpSetup();

    $this->actingAs($owner)
        ->patch(route('follow-ups.cancel', $followUp))
        ->assertRedirect();

    expect(FollowUp::withoutGlobalScopes()->find($followUp->id)->status)->toBe(FollowUpStatus::Cancelled);
});

it('cancelling an already-cancelled follow-up fails', function (): void {
    ['owner' => $owner, 'followUp' => $followUp] = fcpSetup();
    $followUp->updateQuietly(['status' => 'cancelled']);

    $this->actingAs($owner)
        ->patch(route('follow-ups.cancel', $followUp))
        ->assertSessionHasErrors('status');
});

// ---------------------------------------------------------------------------
// Authorization — followUps.dismiss (owner, manager, receptionist)
// ---------------------------------------------------------------------------

it('manager can complete a follow-up', function (): void {
    ['clinic' => $clinic, 'followUp' => $followUp] = fcpSetup();
    $manager = User::factory()->create();
    fcpRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->patch(route('follow-ups.complete', $followUp), [])
        ->assertRedirect();
});

it('receptionist can complete a follow-up', function (): void {
    ['clinic' => $clinic, 'followUp' => $followUp] = fcpSetup();
    $receptionist = User::factory()->create();
    fcpRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->patch(route('follow-ups.complete', $followUp), [])
        ->assertRedirect();
});

it('assistant (lacks followUps.dismiss and has no own-case path) gets 403', function (): void {
    ['clinic' => $clinic, 'followUp' => $followUp] = fcpSetup();
    $assistant = User::factory()->create();
    fcpRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->patch(route('follow-ups.complete', $followUp), [])
        ->assertForbidden();

    expect(FollowUp::withoutGlobalScopes()->find($followUp->id)->status)->toBe(FollowUpStatus::Open);
});

// ---------------------------------------------------------------------------
// Own-case ownership branch (doctor, no followUps.dismiss)
// ---------------------------------------------------------------------------

it('doctor gets 403 on a case-less follow-up (no ownership path without a case)', function (): void {
    ['clinic' => $clinic, 'followUp' => $followUp] = fcpSetup();
    $doctorUser = User::factory()->create();
    fcpRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->patch(route('follow-ups.complete', $followUp), [])
        ->assertForbidden();
});

it("doctor gets 403 completing a follow-up on another doctor's case", function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = fcpSetup();

    $doctorUserA = User::factory()->create();
    fcpRole($doctorUserA, 'doctor', $clinic->id);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    fcpRole($doctorUserB, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctorA->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $followUp = FollowUp::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'case_id' => $case->id,
    ]);

    $this->actingAs($doctorUserB)
        ->patch(route('follow-ups.complete', $followUp), [])
        ->assertForbidden();
});

it('doctor can complete a follow-up on their own case (ownership branch, no followUps.dismiss)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = fcpSetup();

    $doctorUser = User::factory()->create();
    fcpRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    // Confirm the doctor role does not hold followUps.dismiss, so this proves the ownership path.
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $lacksDismiss = ! $doctorUser->can('followUps.dismiss');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    expect($lacksDismiss)->toBeTrue('doctor must not have followUps.dismiss so this test proves the ownership branch');

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $followUp = FollowUp::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'case_id' => $case->id,
    ]);

    $this->actingAs($doctorUser)
        ->patch(route('follow-ups.complete', $followUp), [])
        ->assertRedirect();

    expect(FollowUp::withoutGlobalScopes()->find($followUp->id)->status)->toBe(FollowUpStatus::Done);
});

it('unauthenticated user is redirected to login on complete', function (): void {
    ['followUp' => $followUp] = fcpSetup();

    $this->patch(route('follow-ups.complete', $followUp))
        ->assertRedirect(route('login'));
});
