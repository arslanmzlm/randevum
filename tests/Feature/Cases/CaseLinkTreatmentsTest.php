<?php

use App\Enums\AppointmentStatus;
use App\Enums\CaseStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\StatusLog;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\Carbon;
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
 * Assign a clinic-scoped role (case-link-treatments tests).
 */
function cltRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic with owner, doctor, and patient.
 *
 * @return array{clinic: Clinic, owner: User, doctorUser: User, doctor: Doctor, patient: Patient}
 */
function cltSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cltRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    cltRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'doctorUser', 'doctor', 'patient');
}

/**
 * Create a completed treatment (with a completed appointment) in the given clinic.
 *
 * @param  array<string, mixed>  $overrides
 */
function cltCompletedTreatment(Clinic $clinic, Doctor $doctor, Patient $patient, User $actor, array $overrides = []): Treatment
{
    $startsAt = Carbon::now()->subDays(5);
    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Completed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
        'case_id' => null,
    ]);

    $detail = PodiatryTreatmentDetail::create([]);

    return Treatment::create(array_merge([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'case_id' => null,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Completed,
        'completed_at' => now()->subDays(5),
        'created_by' => $actor->id,
    ], $overrides));
}

// ---------------------------------------------------------------------------
// POST /cases — store a new case from ungrouped completed treatments
// ---------------------------------------------------------------------------

it('creates an Open case and links completed treatments, setting their case_id', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();
    $treatment = cltCompletedTreatment($clinic, $doctor, $patient, $owner);

    $this->actingAs($owner)
        ->post(route('cases.store'), [
            'patient_id' => $patient->id,
            'title' => 'New Case From Treatment',
            'treatment_ids' => [$treatment->id],
        ])
        ->assertRedirect();

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    expect($freshTreatment->case_id)->not->toBeNull();

    $case = CaseRecord::withoutGlobalScopes()->find($freshTreatment->case_id);
    expect($case->status)->toBe(CaseStatus::Open)
        ->and($case->title)->toBe('New Case From Treatment')
        ->and($case->patient_id)->toBe($patient->id)
        ->and($case->doctor_id)->toBe($doctor->id);
});

it('syncs the appointment case_id when creating a case from treatments', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();
    $treatment = cltCompletedTreatment($clinic, $doctor, $patient, $owner);
    $appointmentId = $treatment->appointment_id;

    $this->actingAs($owner)
        ->post(route('cases.store'), [
            'patient_id' => $patient->id,
            'title' => 'Sync Appointment Case Test',
            'treatment_ids' => [$treatment->id],
        ]);

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    $freshAppointment = Appointment::withoutGlobalScopes()->find($appointmentId);

    expect($freshAppointment->case_id)->toBe($freshTreatment->case_id);
});

it('writes a null → open status_log for a newly created case', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();
    $treatment = cltCompletedTreatment($clinic, $doctor, $patient, $owner);

    $this->actingAs($owner)
        ->post(route('cases.store'), [
            'patient_id' => $patient->id,
            'title' => 'Status Log Test Case',
            'treatment_ids' => [$treatment->id],
        ]);

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'case')
        ->where('loggable_id', $freshTreatment->case_id)
        ->where('from_status', null)
        ->where('to_status', 'open')
        ->first();

    expect($log)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// POST /cases/{case}/treatments — link ungrouped treatments to an open case
// ---------------------------------------------------------------------------

it('links completed ungrouped treatments to an open case', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $treatment = cltCompletedTreatment($clinic, $doctor, $patient, $owner);
    $appointmentId = $treatment->appointment_id;

    $this->actingAs($owner)
        ->post(route('cases.treatments.link', $case), [
            'treatment_ids' => [$treatment->id],
        ])
        ->assertRedirect(route('cases.show', $case));

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    $freshAppointment = Appointment::withoutGlobalScopes()->find($appointmentId);

    expect($freshTreatment->case_id)->toBe($case->id)
        ->and($freshAppointment->case_id)->toBe($case->id);
});

// ---------------------------------------------------------------------------
// Guard: rejected when treatment already has a case_id
// ---------------------------------------------------------------------------

it('rejects linkTreatments when a treatment already has a case_id', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();

    $existingCase = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $targetCase = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $alreadyLinked = cltCompletedTreatment($clinic, $doctor, $patient, $owner, [
        'case_id' => $existingCase->id,
    ]);

    $this->actingAs($owner)
        ->post(route('cases.treatments.link', $targetCase), [
            'treatment_ids' => [$alreadyLinked->id],
        ])
        ->assertSessionHasErrors('treatment_ids');

    // case_id must remain on the original case
    expect(Treatment::withoutGlobalScopes()->find($alreadyLinked->id)->case_id)->toBe($existingCase->id);
});

// ---------------------------------------------------------------------------
// Guard: rejected when treatment status is not Completed
// ---------------------------------------------------------------------------

it('rejects linkTreatments when the treatment is Draft (not Completed)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $startsAt = Carbon::now()->subDays(2);
    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Arrived)->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $detail = PodiatryTreatmentDetail::create([]);
    $draftTreatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'case_id' => null,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Draft,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->post(route('cases.treatments.link', $case), [
            'treatment_ids' => [$draftTreatment->id],
        ])
        ->assertSessionHasErrors('treatment_ids');

    expect(Treatment::withoutGlobalScopes()->find($draftTreatment->id)->case_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Guard: rejected when treatment belongs to a different patient
// ---------------------------------------------------------------------------

it('rejects linkTreatments when the treatment belongs to a different patient', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();

    $otherPatient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $wrongPatientTreatment = cltCompletedTreatment($clinic, $doctor, $otherPatient, $owner);

    $this->actingAs($owner)
        ->post(route('cases.treatments.link', $case), [
            'treatment_ids' => [$wrongPatientTreatment->id],
        ])
        ->assertSessionHasErrors('treatment_ids');
});

// ---------------------------------------------------------------------------
// Guard: rejected when treatment belongs to a different doctor
// ---------------------------------------------------------------------------

it('rejects linkTreatments when the treatment belongs to a different doctor', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $wrongDoctorTreatment = cltCompletedTreatment($clinic, $otherDoctor, $patient, $owner);

    $this->actingAs($owner)
        ->post(route('cases.treatments.link', $case), [
            'treatment_ids' => [$wrongDoctorTreatment->id],
        ])
        ->assertSessionHasErrors('treatment_ids');
});

// ---------------------------------------------------------------------------
// Linking is allowed on any non-closed case (R1) — not just Open
// ---------------------------------------------------------------------------

it('links treatments to a Suspended case', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);
    $case->updateQuietly(['status' => 'suspended', 'suspended_at' => now()]);

    $treatment = cltCompletedTreatment($clinic, $doctor, $patient, $owner);

    $this->actingAs($owner)
        ->post(route('cases.treatments.link', $case), ['treatment_ids' => [$treatment->id]])
        ->assertRedirect(route('cases.show', $case));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->case_id)->toBe($case->id);
});

it('links treatments to a follow_up-status case', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);
    $case->updateQuietly(['status' => 'follow_up']);

    $treatment = cltCompletedTreatment($clinic, $doctor, $patient, $owner);

    $this->actingAs($owner)
        ->post(route('cases.treatments.link', $case), ['treatment_ids' => [$treatment->id]])
        ->assertRedirect(route('cases.show', $case));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->case_id)->toBe($case->id);
});

// ---------------------------------------------------------------------------
// Guard: rejected when the target case is Closed
// ---------------------------------------------------------------------------

it('rejects linkTreatments when the case is Closed', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cltSetup();

    $closedCase = CaseRecord::factory()->closed()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id,
        'doctor_id' => $doctor->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $treatment = cltCompletedTreatment($clinic, $doctor, $patient, $owner);

    $this->actingAs($owner)
        ->post(route('cases.treatments.link', $closedCase), [
            'treatment_ids' => [$treatment->id],
        ])
        ->assertSessionHasErrors('case_id');

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->case_id)->toBeNull();
});
