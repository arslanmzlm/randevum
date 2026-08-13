<?php

use App\Enums\FollowUpStatus;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\FollowUpType;
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
 * Assign a clinic-scoped role (follow-up create tests).
 */
function fcrRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, type: FollowUpType}
 */
function fcrSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    fcrRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $type = FollowUpType::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'doctor', 'patient', 'type');
}

/**
 * @return array<string, mixed>
 */
function fcrPayload(Patient $patient, FollowUpType $type, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patient->id,
        'follow_up_type_id' => $type->id,
        'due_date' => today()->addDays(3)->format('Y-m-d'),
        'note' => 'Call about payment',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Create — with / without a case
// ---------------------------------------------------------------------------

it('owner creates a patient-only follow-up (no case)', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'type' => $type] = fcrSetup();

    $this->actingAs($owner)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type))
        ->assertRedirect();

    $followUp = FollowUp::withoutGlobalScopes()->where('patient_id', $patient->id)->first();

    expect($followUp)->not->toBeNull()
        ->and($followUp->case_id)->toBeNull()
        ->and($followUp->status)->toBe(FollowUpStatus::Open)
        ->and($followUp->created_by_user_id)->toBe($owner->id)
        ->and($followUp->follow_up_type_id)->toBe($type->id);
});

it('owner creates a follow-up linked to a case', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient, 'type' => $type] = fcrSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type, ['case_id' => $case->id]))
        ->assertRedirect();

    $followUp = FollowUp::withoutGlobalScopes()->where('patient_id', $patient->id)->first();
    expect($followUp->case_id)->toBe($case->id);
});

it('writes a null -> open status_log on create', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'type' => $type] = fcrSetup();

    $this->actingAs($owner)->post(route('follow-ups.store'), fcrPayload($patient, $type));

    $followUp = FollowUp::withoutGlobalScopes()->where('patient_id', $patient->id)->first();

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'follow_up')
        ->where('loggable_id', $followUp->id)
        ->where('from_status', null)
        ->where('to_status', 'open')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->by_user_id)->toBe($owner->id);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('rejects create without a follow_up_type_id', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'type' => $type] = fcrSetup();

    $this->actingAs($owner)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type, ['follow_up_type_id' => null]))
        ->assertSessionHasErrors('follow_up_type_id');
});

it('rejects create without a due_date', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'type' => $type] = fcrSetup();

    $this->actingAs($owner)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type, ['due_date' => null]))
        ->assertSessionHasErrors('due_date');
});

it('rejects create with a deactivated follow_up_type_id', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = fcrSetup();
    $inactiveType = FollowUpType::factory()->inactive()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('follow-ups.store'), fcrPayload($patient, $inactiveType))
        ->assertSessionHasErrors('follow_up_type_id');

    expect(FollowUp::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

it('rejects create for a soft-deleted patient', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'type' => $type] = fcrSetup();
    $patient->delete();

    $this->actingAs($owner)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type))
        ->assertSessionHasErrors('patient_id');

    expect(FollowUp::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

it('rejects create when case_id belongs to a different patient', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient, 'type' => $type] = fcrSetup();

    $otherPatient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $otherPatient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type, ['case_id' => $case->id]))
        ->assertSessionHasErrors('case_id');

    expect(FollowUp::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Authorization — followUps.create (owner, manager, receptionist, doctor)
// ---------------------------------------------------------------------------

it('manager can create a follow-up', function (): void {
    ['clinic' => $clinic, 'patient' => $patient, 'type' => $type] = fcrSetup();
    $manager = User::factory()->create();
    fcrRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type))
        ->assertRedirect();
});

it('receptionist can create a follow-up', function (): void {
    ['clinic' => $clinic, 'patient' => $patient, 'type' => $type] = fcrSetup();
    $receptionist = User::factory()->create();
    fcrRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type))
        ->assertRedirect();
});

it('doctor can create a follow-up (followUps.create includes doctor)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient, 'type' => $type] = fcrSetup();
    $doctorUser = User::factory()->create();
    fcrRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type))
        ->assertRedirect();
});

it('assistant (lacks followUps.create) gets 403', function (): void {
    ['clinic' => $clinic, 'patient' => $patient, 'type' => $type] = fcrSetup();
    $assistant = User::factory()->create();
    fcrRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('follow-ups.store'), fcrPayload($patient, $type))
        ->assertForbidden();

    expect(FollowUp::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

it('unauthenticated user is redirected to login', function (): void {
    ['patient' => $patient, 'type' => $type] = fcrSetup();

    $this->post(route('follow-ups.store'), fcrPayload($patient, $type))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Case picker endpoint (follow-ups.cases) — patient_id existence gate
// ---------------------------------------------------------------------------

it('returns the patient cases for the case picker', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = fcrSetup();

    $case = CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($owner)
        ->getJson(route('follow-ups.cases', ['patient_id' => $patient->id]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $case->id);
});

it('rejects a patient id from another clinic on the case picker (exists rule scoped to active clinic)', function (): void {
    ['owner' => $owner] = fcrSetup();

    $otherClinic = Clinic::factory()->create();
    $otherPatient = Patient::factory()->create(['clinic_id' => $otherClinic->id]);

    $this->actingAs($owner)
        ->getJson(route('follow-ups.cases', ['patient_id' => $otherPatient->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('patient_id');
});

it('rejects a soft-deleted patient id on the case picker', function (): void {
    ['owner' => $owner, 'patient' => $patient] = fcrSetup();

    $patient->delete();

    $this->actingAs($owner)
        ->getJson(route('follow-ups.cases', ['patient_id' => $patient->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('patient_id');
});

it('rejects a patient id that exists in no clinic on the case picker', function (): void {
    ['owner' => $owner] = fcrSetup();

    $this->actingAs($owner)
        ->getJson(route('follow-ups.cases', ['patient_id' => 999999]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('patient_id');
});
