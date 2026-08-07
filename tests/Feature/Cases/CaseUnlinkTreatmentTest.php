<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
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
 * Assign a clinic-scoped role (case-unlink-treatment tests).
 */
function cutRole(User $user, string $role, int $clinicId): void
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
function cutSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cutRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    cutRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'doctorUser', 'doctor', 'patient');
}

/**
 * A case with one completed treatment already linked (treatment + its appointment's case_id set),
 * optionally forced into a non-open status.
 *
 * @return array{case: CaseRecord, treatment: Treatment}
 */
function cutLinkedCase(Clinic $clinic, Doctor $doctor, Patient $patient, User $actor, string $status = 'open'): array
{
    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $startsAt = Carbon::now()->subDays(5);
    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Completed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
        'case_id' => $case->id,
    ]);

    $detail = PodiatryTreatmentDetail::create([]);
    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'case_id' => $case->id,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Completed,
        'completed_at' => now()->subDays(5),
        'created_by' => $actor->id,
    ]);

    if ($status !== 'open') {
        $updates = ['status' => $status];
        if ($status === 'suspended') {
            $updates['suspended_at'] = now();
        } elseif ($status === 'closed') {
            $updates['closed_at'] = now();
        }
        $case->updateQuietly($updates);
        $case->refresh();
    }

    return compact('case', 'treatment');
}

// ---------------------------------------------------------------------------
// Unlink clears both the treatment and the appointment case_id
// ---------------------------------------------------------------------------

it('unlink clears the treatment case_id and the appointment case_id', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cutSetup();
    ['case' => $case, 'treatment' => $treatment] = cutLinkedCase($clinic, $doctor, $patient, $owner);

    $this->actingAs($owner)
        ->delete(route('cases.treatments.unlink', [$case, $treatment]))
        ->assertRedirect();

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    $freshAppointment = Appointment::withoutGlobalScopes()->find($treatment->appointment_id);

    expect($freshTreatment->case_id)->toBeNull()
        ->and($freshAppointment->case_id)->toBeNull();
});

it('unlink flashes a success toast', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cutSetup();
    ['case' => $case, 'treatment' => $treatment] = cutLinkedCase($clinic, $doctor, $patient, $owner);

    $this->actingAs($owner)
        ->delete(route('cases.treatments.unlink', [$case, $treatment]))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// Allowed on any non-closed status (R1) — open, suspended, follow_up
// ---------------------------------------------------------------------------

dataset('non-closed case statuses', ['open', 'suspended', 'follow_up']);

it('unlink is allowed on a non-closed case', function (string $status): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cutSetup();
    ['case' => $case, 'treatment' => $treatment] = cutLinkedCase($clinic, $doctor, $patient, $owner, $status);

    $this->actingAs($owner)
        ->delete(route('cases.treatments.unlink', [$case, $treatment]))
        ->assertRedirect();

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->case_id)->toBeNull();
})->with('non-closed case statuses');

// ---------------------------------------------------------------------------
// Rejected on Closed
// ---------------------------------------------------------------------------

it('unlink is rejected on a Closed case', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cutSetup();
    ['case' => $case, 'treatment' => $treatment] = cutLinkedCase($clinic, $doctor, $patient, $owner, 'closed');

    $this->actingAs($owner)
        ->delete(route('cases.treatments.unlink', [$case, $treatment]))
        ->assertSessionHasErrors('case_id');

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->case_id)->toBe($case->id);
});

// ---------------------------------------------------------------------------
// Guard: the treatment must actually belong to this case
// ---------------------------------------------------------------------------

it('a treatment belonging to a different case is rejected', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = cutSetup();
    ['case' => $caseA] = cutLinkedCase($clinic, $doctor, $patient, $owner);
    ['case' => $caseB, 'treatment' => $treatmentB] = cutLinkedCase($clinic, $doctor, $patient, $owner);

    $this->actingAs($owner)
        ->delete(route('cases.treatments.unlink', [$caseA, $treatmentB]))
        ->assertSessionHasErrors('treatment_id');

    expect(Treatment::withoutGlobalScopes()->find($treatmentB->id)->case_id)->toBe($caseB->id);
});

// ---------------------------------------------------------------------------
// Authorization mirrors cases.update (viewAll || ownsCase)
// ---------------------------------------------------------------------------

it('doctor can unlink a treatment on their own case', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $doctor, 'patient' => $patient] = cutSetup();
    ['case' => $case, 'treatment' => $treatment] = cutLinkedCase($clinic, $doctor, $patient, $doctorUser);

    $this->actingAs($doctorUser)
        ->delete(route('cases.treatments.unlink', [$case, $treatment]))
        ->assertRedirect();
});

it("doctor B gets 403 unlinking a treatment on doctor A's case", function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctorA, 'patient' => $patient] = cutSetup();
    ['case' => $case, 'treatment' => $treatment] = cutLinkedCase($clinic, $doctorA, $patient, $owner);

    $doctorUserB = User::factory()->create();
    cutRole($doctorUserB, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($doctorUserB)
        ->delete(route('cases.treatments.unlink', [$case, $treatment]))
        ->assertForbidden();

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->case_id)->toBe($case->id);
});
