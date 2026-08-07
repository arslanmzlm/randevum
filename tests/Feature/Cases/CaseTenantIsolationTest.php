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
 * Assign a clinic-scoped role (case tenant-isolation tests).
 */
function ctiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a self-contained clinic fixture (owner + doctor + patient + case).
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, doctorUser: User, patient: Patient, case: CaseRecord}
 */
function ctiClinicSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ctiRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    ctiRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    return compact('clinic', 'owner', 'doctor', 'doctorUser', 'patient', 'case');
}

/**
 * Create a completed treatment with appointment in the given clinic.
 */
function ctiCompletedTreatment(Clinic $clinic, Doctor $doctor, Patient $patient, User $actor): Treatment
{
    $startsAt = Carbon::now()->subDays(3);
    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Completed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
        'case_id' => null,
    ]);

    $detail = PodiatryTreatmentDetail::create([]);

    return Treatment::create([
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
        'completed_at' => now()->subDays(3),
        'created_by' => $actor->id,
    ]);
}

// ---------------------------------------------------------------------------
// cases.show — ClinicScope causes clinic B's case to 404 for clinic A's user
// ---------------------------------------------------------------------------

it("clinic A owner gets 404 on clinic B's case show (ClinicScope isolation)", function (): void {
    $setupA = ctiClinicSetup();
    $setupB = ctiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->get(route('cases.show', $setupB['case']))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// cases.status.update — clinic B's case not visible to clinic A
// ---------------------------------------------------------------------------

it("clinic A owner gets 404 on clinic B's case status update", function (): void {
    $setupA = ctiClinicSetup();
    $setupB = ctiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->patch(route('cases.status.update', $setupB['case']), ['status' => 'closed'])
        ->assertNotFound();

    // B's case must remain Open
    $fresh = CaseRecord::withoutGlobalScopes()->find($setupB['case']->id);
    expect($fresh->status->value)->toBe('open');
});

// ---------------------------------------------------------------------------
// cases.notes.update — clinic B's case not visible to clinic A
// ---------------------------------------------------------------------------

it("clinic A owner gets 404 on clinic B's case notes update", function (): void {
    $setupA = ctiClinicSetup();
    $setupB = ctiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->patch(route('cases.notes.update', $setupB['case']), ['notes' => 'Injected note'])
        ->assertNotFound();

    // B's case notes must be untouched
    $fresh = CaseRecord::withoutGlobalScopes()->find($setupB['case']->id);
    expect($fresh->notes)->toBeNull();
});

// ---------------------------------------------------------------------------
// cases.treatments.link — clinic B's case not accessible from clinic A
// ---------------------------------------------------------------------------

it("clinic A owner gets 404 trying to link treatments to clinic B's case", function (): void {
    $setupA = ctiClinicSetup();
    $setupB = ctiClinicSetup();

    $treatmentA = ctiCompletedTreatment($setupA['clinic'], $setupA['doctor'], $setupA['patient'], $setupA['owner']);

    $this->actingAs($setupA['owner'])
        ->post(route('cases.treatments.link', $setupB['case']), [
            'treatment_ids' => [$treatmentA->id],
        ])
        ->assertNotFound();

    // Clinic A's treatment case_id must remain null
    expect(Treatment::withoutGlobalScopes()->find($treatmentA->id)->case_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Cannot link clinic B's treatment into clinic A's case
// ---------------------------------------------------------------------------

it("clinic A cannot link clinic B's treatment into clinic A's case", function (): void {
    $setupA = ctiClinicSetup();
    $setupB = ctiClinicSetup();

    $treatmentB = ctiCompletedTreatment($setupB['clinic'], $setupB['doctor'], $setupB['patient'], $setupB['owner']);

    $this->actingAs($setupA['owner'])
        ->post(route('cases.treatments.link', $setupA['case']), [
            'treatment_ids' => [$treatmentB->id],
        ])
        ->assertSessionHasErrors('treatment_ids');

    // Clinic B's treatment must remain unlinked
    expect(Treatment::withoutGlobalScopes()->find($treatmentB->id)->case_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// cases.index — clinic A's index never exposes clinic B's cases
// ---------------------------------------------------------------------------

it("clinic A's case index never exposes clinic B's cases", function (): void {
    $setupA = ctiClinicSetup();
    $setupB = ctiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->get(route('cases.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('cases.data', 1)
            ->where('cases.data.0.id', $setupA['case']->id)
        );
});

// ---------------------------------------------------------------------------
// POST /cases — cannot create a case for clinic B's patient
// ---------------------------------------------------------------------------

it("clinic A owner cannot create a case for clinic B's patient", function (): void {
    $setupA = ctiClinicSetup();
    $setupB = ctiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->post(route('cases.store'), [
            'patient_id' => $setupB['patient']->id,
            'title' => 'Cross-clinic Case Attempt',
            'doctor_id' => $setupA['doctor']->id,
        ])
        ->assertSessionHasErrors('patient_id');
});

// ---------------------------------------------------------------------------
// POST /cases — cannot open a case with clinic B's doctor_id
// ---------------------------------------------------------------------------

it("clinic A owner cannot create a case for own patient with clinic B's doctor", function (): void {
    $setupA = ctiClinicSetup();
    $setupB = ctiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->post(route('cases.store'), [
            'patient_id' => $setupA['patient']->id,
            'title' => 'Cross-clinic Doctor Attempt',
            'doctor_id' => $setupB['doctor']->id,
        ])
        ->assertSessionHasErrors('doctor_id');
});
