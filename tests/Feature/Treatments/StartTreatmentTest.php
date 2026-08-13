<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Models\StatusLog;
use App\Models\Treatment;
use App\Models\User;
use App\Models\Vertical;
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
 * Assign a clinic-scoped role (start-treatment tests).
 */
function stRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a consistent setup: clinic + owner + doctor profile + patient + appointment.
 *
 * @return array{clinic: Clinic, owner: User, doctorUser: User, doctor: Doctor, patient: Patient, appointment: Appointment}
 */
function stSetup(AppointmentStatus $status = AppointmentStatus::Confirmed): array
{
    $vertical = Vertical::factory()->podiatry()->create();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $owner = User::factory()->create();
    stRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->addDays(1);
    $appointment = Appointment::factory()->withStatus($status)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    return compact('clinic', 'owner', 'doctorUser', 'doctor', 'patient', 'appointment');
}

/**
 * Create a draft Treatment row directly (bypassing the service layer).
 */
function stDraftTreatment(Clinic $clinic, Appointment $appointment, Patient $patient, Doctor $doctor, User $creator, TreatmentStatus $status = TreatmentStatus::Draft): Treatment
{
    return Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => $status,
        'completed_at' => $status === TreatmentStatus::Completed ? now() : null,
        'created_by' => $creator->id,
    ]);
}

// ---------------------------------------------------------------------------
// POST /appointments/{appointment}/treatment — core creation behavior
// ---------------------------------------------------------------------------

it('creates a draft treatment for a confirmed appointment', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'appointment' => $appointment] = stSetup();

    $this->actingAs($owner)
        ->post(route('treatments.start', $appointment))
        ->assertRedirect();

    $treatment = Treatment::withoutGlobalScopes()
        ->where('appointment_id', $appointment->id)
        ->first();

    expect($treatment)->not->toBeNull()
        ->and($treatment->status)->toBe(TreatmentStatus::Draft)
        ->and($treatment->clinic_id)->toBe($clinic->id)
        ->and($treatment->patient_id)->toBe($appointment->patient_id)
        ->and($treatment->doctor_id)->toBe($appointment->doctor_id);
});

it('starts the clinical fields empty and without a vertical detail row', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup();

    $this->actingAs($owner)->post(route('treatments.start', $appointment));

    $treatment = Treatment::withoutGlobalScopes()->where('appointment_id', $appointment->id)->first();

    expect($treatment->complaint)->toBeNull()
        ->and($treatment->diagnosis)->toBeNull()
        ->and($treatment->treatment_process)->toBeNull()
        ->and($treatment->details_type)->toBeNull()
        ->and($treatment->details_id)->toBeNull();
});

it('redirects to the process screen after creating a draft treatment', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup();

    $this->actingAs($owner)
        ->post(route('treatments.start', $appointment))
        ->assertRedirect(
            route('treatments.process', Treatment::withoutGlobalScopes()->where('appointment_id', $appointment->id)->first())
        );
});

it('writes a null → draft status_log for the new treatment', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup();

    $this->actingAs($owner)->post(route('treatments.start', $appointment));

    $treatment = Treatment::withoutGlobalScopes()->where('appointment_id', $appointment->id)->first();

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'treatment')
        ->where('loggable_id', $treatment->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBeNull()
        ->and($log->to_status)->toBe('draft')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('transitions the appointment from confirmed to arrived and logs it', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup(AppointmentStatus::Confirmed);

    $this->actingAs($owner)->post(route('treatments.start', $appointment));

    $fresh = Appointment::withoutGlobalScopes()->find($appointment->id);
    expect($fresh->status)->toBe(AppointmentStatus::Arrived);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'arrived')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('confirmed')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('transitions the appointment from rescheduled to arrived', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup(AppointmentStatus::Rescheduled);

    $this->actingAs($owner)->post(route('treatments.start', $appointment));

    $fresh = Appointment::withoutGlobalScopes()->find($appointment->id);
    expect($fresh->status)->toBe(AppointmentStatus::Arrived);
});

it('does not write an extra arrived log when appointment is already arrived', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup(AppointmentStatus::Arrived);

    $this->actingAs($owner)->post(route('treatments.start', $appointment));

    $arrivedLogs = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'arrived')
        ->count();

    expect($arrivedLogs)->toBe(0); // appointment was already arrived — no transition logged
});

// ---------------------------------------------------------------------------
// Idempotency
// ---------------------------------------------------------------------------

it('returns the existing draft treatment without creating a duplicate', function (): void {
    ['owner' => $owner, 'appointment' => $appointment, 'clinic' => $clinic,
        'doctor' => $doctor, 'patient' => $patient] = stSetup(AppointmentStatus::Arrived);

    $existing = stDraftTreatment($clinic, $appointment, $patient, $doctor, $owner);

    $this->actingAs($owner)
        ->post(route('treatments.start', $appointment))
        ->assertRedirect(route('treatments.process', $existing));

    expect(Treatment::withoutGlobalScopes()->where('appointment_id', $appointment->id)->count())->toBe(1);
});

it('redirects to the show page when a completed treatment already exists', function (): void {
    ['owner' => $owner, 'appointment' => $appointment, 'clinic' => $clinic,
        'doctor' => $doctor, 'patient' => $patient] = stSetup(AppointmentStatus::Arrived);

    $existing = stDraftTreatment($clinic, $appointment, $patient, $doctor, $owner, TreatmentStatus::Completed);

    $this->actingAs($owner)
        ->post(route('treatments.start', $appointment))
        ->assertRedirect(route('treatments.show', $existing));
});

// ---------------------------------------------------------------------------
// Guard: invalid appointment statuses
// ---------------------------------------------------------------------------

it('returns 422 when the appointment is cancelled', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup(AppointmentStatus::Cancelled);

    $this->actingAs($owner)
        ->post(route('treatments.start', $appointment))
        ->assertSessionHasErrors('appointment');

    expect(Treatment::withoutGlobalScopes()->where('appointment_id', $appointment->id)->exists())->toBeFalse();
});

it('returns 422 when the appointment is completed', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup(AppointmentStatus::Completed);

    $this->actingAs($owner)
        ->post(route('treatments.start', $appointment))
        ->assertSessionHasErrors('appointment');
});

it('returns 422 when the appointment is no_show', function (): void {
    ['owner' => $owner, 'appointment' => $appointment] = stSetup(AppointmentStatus::NoShow);

    $this->actingAs($owner)
        ->post(route('treatments.start', $appointment))
        ->assertSessionHasErrors('appointment');
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('returns 403 when the user lacks treatments.create (receptionist role)', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    stRole($receptionist, 'receptionist', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->addDays(1);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $this->actingAs($receptionist)
        ->post(route('treatments.start', $appointment))
        ->assertForbidden();
});

it('doctor can start their own appointment', function (): void {
    $vertical = Vertical::factory()->podiatry()->create();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $doctorUser = User::factory()->create();
    stRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->addDays(1);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $this->actingAs($doctorUser)
        ->post(route('treatments.start', $appointment))
        ->assertRedirect();

    expect(Treatment::withoutGlobalScopes()->where('appointment_id', $appointment->id)->exists())->toBeTrue();
});

it("doctor cannot start another doctor's appointment (lacks appointments.viewAll)", function (): void {
    $clinic = Clinic::factory()->create();

    $doctorUserA = User::factory()->create();
    stRole($doctorUserA, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->addDays(1);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $this->actingAs($doctorUserA)
        ->post(route('treatments.start', $appointment))
        ->assertForbidden();

    expect(Treatment::withoutGlobalScopes()->where('appointment_id', $appointment->id)->exists())->toBeFalse();
});

it('manager and assistant can start a treatment', function (): void {
    foreach (['manager', 'assistant'] as $role) {
        $clinic = Clinic::factory()->create();
        $user = User::factory()->create();
        stRole($user, $role, $clinic->id);

        $doctorUser = User::factory()->create();
        $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
        $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

        $startsAt = Carbon::now()->addDays(1);
        $appointment = Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
        ]);

        $this->actingAs($user)
            ->post(route('treatments.start', $appointment))
            ->assertRedirect();
    }
});

// ---------------------------------------------------------------------------
// GET /treatments/{treatment}/process
// ---------------------------------------------------------------------------

it('GET process returns the Process component with required Inertia props', function (): void {
    ['owner' => $owner, 'appointment' => $appointment, 'clinic' => $clinic,
        'doctor' => $doctor, 'patient' => $patient] = stSetup(AppointmentStatus::Arrived);

    $treatment = stDraftTreatment($clinic, $appointment, $patient, $doctor, $owner);

    $this->actingAs($owner)
        ->get(route('treatments.process', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Process')
            ->has('treatment')
            ->has('services')
            ->has('products')
            ->has('openCases')
            ->has('defaultSlotDuration')
            ->has('workingHours')
        );
});

// The Process screen copies these templates into complaint / diagnosis / treatment_process when a
// service is picked, so the prop must carry them even though the trio now lives on `treatments`.
it('GET process ships each service with its clinical templates', function (): void {
    ['owner' => $owner, 'appointment' => $appointment, 'clinic' => $clinic,
        'doctor' => $doctor, 'patient' => $patient] = stSetup(AppointmentStatus::Arrived);

    Service::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'default_complaint' => 'Batık tırnak şikayeti',
        'default_diagnosis' => 'Onikokriptoz',
        'default_treatment_process' => 'Kenar rezeksiyonu',
    ]);

    $treatment = stDraftTreatment($clinic, $appointment, $patient, $doctor, $owner);

    $this->actingAs($owner)
        ->get(route('treatments.process', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('services.0.default_complaint', 'Batık tırnak şikayeti')
            ->where('services.0.default_diagnosis', 'Onikokriptoz')
            ->where('services.0.default_treatment_process', 'Kenar rezeksiyonu')
        );
});

it('GET process redirects a completed treatment to the show page', function (): void {
    ['owner' => $owner, 'appointment' => $appointment, 'clinic' => $clinic,
        'doctor' => $doctor, 'patient' => $patient] = stSetup(AppointmentStatus::Arrived);

    $treatment = stDraftTreatment($clinic, $appointment, $patient, $doctor, $owner, TreatmentStatus::Completed);

    $this->actingAs($owner)
        ->get(route('treatments.process', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));
});

it('flags treatment.doctor.is_deleted on the process and show screens for a soft-deleted doctor', function (): void {
    ['owner' => $owner, 'appointment' => $appointment, 'clinic' => $clinic,
        'doctor' => $doctor, 'patient' => $patient] = stSetup(AppointmentStatus::Arrived);

    $treatment = stDraftTreatment($clinic, $appointment, $patient, $doctor, $owner);

    $this->actingAs($owner)
        ->get(route('treatments.process', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('treatment.doctor.is_deleted', false));

    $name = $doctor->display_name;
    $doctor->delete();

    $this->actingAs($owner)
        ->get(route('treatments.process', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatment.doctor.display_name', $name)
            ->where('treatment.doctor.is_deleted', true)
        );

    $treatment->update(['status' => TreatmentStatus::Completed, 'completed_at' => now()]);

    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatment.doctor.display_name', $name)
            ->where('treatment.doctor.is_deleted', true)
        );
});
