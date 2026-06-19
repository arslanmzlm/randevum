<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleException;
use App\Models\StatusLog;
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
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function raRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Clinic-local slot on next Monday at a given HH:MM (Europe/Istanbul).
 * 10:00 and 11:00 are both inside working hours (09:00-19:00) and outside the break (12:00-13:30).
 */
function raMondayAt(string $hhmm = '10:00'): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' '.$hhmm.':00';
}

/**
 * Build a valid PUT /appointments/{appointment} payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function raPayload(int $doctorId, string $startsAt, array $overrides = []): array
{
    return array_merge([
        'doctor_id' => $doctorId,
        'starts_at' => $startsAt,
        'service_id' => null,
        'appointment_type_id' => null,
        'duration_minutes' => null,
    ], $overrides);
}

/**
 * Create a future Confirmed appointment on next Monday at 10:00 Istanbul.
 *
 * @param  array<string, mixed>  $overrides
 */
function raAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
{
    $mondayUtc = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0)->utc();

    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $mondayUtc,
        'ends_at' => $mondayUtc->copy()->addMinutes(30),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// GET /appointments/{appointment}/edit — authorization & props
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /appointments/{appointment}/edit', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->get(route('appointments.edit', $appointment))
        ->assertRedirect(route('login'));
});

it('owner can access GET /appointments/{appointment}/edit and the Edit component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->get(route('appointments.edit', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/Edit')
            ->has('appointment')
            ->has('doctors')
            ->has('services')
            ->has('appointmentTypes')
            ->has('defaultSlotDuration')
            ->has('workingHours')
            ->has('timezone')
            ->has('ownDoctorId')
        );
});

it('edit page appointment prop includes patient read-only block, status, and ISO starts_at', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->get(route('appointments.edit', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('appointment.id', $appointment->id)
            ->where('appointment.doctor_id', $doctor->id)
            ->where('appointment.status', 'confirmed')
            ->has('appointment.patient.id')
            ->has('appointment.patient.full_name')
            ->has('appointment.starts_at')
            ->has('appointment.duration_minutes')
        );
});

it('doctor can access their own appointment edit page', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    raRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($doctorUser)
        ->get(route('appointments.edit', $appointment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('appointments/Edit'));
});

it('doctor cannot access another doctor appointment edit page (403 via policy ownership)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    raRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->get(route('appointments.edit', $appointment))
        ->assertForbidden();
});

it('assistant gets 403 on GET /appointments/{appointment}/edit (no appointments.update permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    raRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($assistant)
        ->get(route('appointments.edit', $appointment))
        ->assertForbidden();
});

it('cross-clinic appointment returns 404 on GET /appointments/{appointment}/edit (multi-tenant)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    raRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $appointmentB = raAppointment($clinicB, $doctorB, $patientB);

    $this->actingAs($ownerA)
        ->get(route('appointments.edit', $appointmentB))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// PUT /appointments/{appointment} — core reschedule behavior
// ---------------------------------------------------------------------------

it('moving start time transitions Confirmed to Rescheduled and writes status_log', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Rescheduled);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'rescheduled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('confirmed')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('ends_at is recomputed from explicit duration_minutes on reschedule', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00'), [
            'duration_minutes' => 60,
        ]))
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect((int) $appointment->starts_at->diffInMinutes($appointment->ends_at))->toBe(60);
});

it('reschedule into own current slot succeeds (exclude-self in conflict layer)', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Appointment is Confirmed at Monday 10:00-10:30.
    $appointment = raAppointment($clinic, $doctor, $patient);

    // PUT the exact same 10:00 slot — without exclude-self the appointment conflicts with itself.
    // With exclude-self the service ignores the row's own current slot → success.
    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('10:00'), [
            'duration_minutes' => 30,
        ]))
        ->assertRedirect(route('appointments.index'));

    // Start time unchanged → status stays Confirmed (no transition logged).
    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Confirmed)
        ->and($appointment->starts_at->setTimezone('Europe/Istanbul')->format('H:i'))->toBe('10:00');
});

it('reschedule is blocked by a different overlapping Confirmed appointment (starts_at error)', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Appointment A is at 10:00 — we will try to reschedule it to 11:00.
    $appointmentA = raAppointment($clinic, $doctor, $patient);

    // Appointment B is at 11:00-11:30 (same doctor) — blocks the target slot.
    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $conflictStart = Carbon::parse("{$mondayDate} 11:00:00", 'Europe/Istanbul')->utc();
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $conflictStart,
        'ends_at' => $conflictStart->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointmentA), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertSessionHasErrors('starts_at');
});

it('reschedule is rejected when new slot is outside working hours (starts_at error)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    // 08:00 Istanbul — before clinic opening (09:00)
    $earlySlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 08:00:00';

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, $earlySlot))
        ->assertSessionHasErrors('starts_at');
});

it('reschedule is rejected when new slot overlaps a schedule exception (starts_at error)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    // Schedule exception blocks 11:00-12:00 Istanbul
    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $exceptionStart = Carbon::parse("{$mondayDate} 11:00:00", 'Europe/Istanbul')->utc();
    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $exceptionStart,
        'ends_at' => $exceptionStart->copy()->addMinutes(60),
    ]);

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertSessionHasErrors('starts_at');
});

it('doctor/service-only edit (same start time) keeps status and writes no additional status_log', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $logCountBefore = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->count();

    // Same 10:00 start, only the doctor changes — no time move, so no status transition.
    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($otherDoctor->id, raMondayAt('10:00'), [
            'duration_minutes' => 30,
        ]))
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Confirmed)
        ->and($appointment->doctor_id)->toBe($otherDoctor->id);

    $logCountAfter = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->count();

    expect($logCountAfter)->toBe($logCountBefore);
});

it('re-rescheduling an already-Rescheduled appointment keeps Rescheduled status with no new log entry', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Rescheduled]);

    $logCountBefore = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->count();

    // Moving to 11:00 — time changes but status is already Rescheduled → no new log entry.
    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Rescheduled);

    $logCountAfter = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->count();

    expect($logCountAfter)->toBe($logCountBefore);
});

it('cannot reschedule a Completed appointment (422 on starts_at)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Completed]);

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertSessionHasErrors('starts_at');
});

it('cannot reschedule a Cancelled appointment (422 on starts_at)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Cancelled]);

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertSessionHasErrors('starts_at');
});

it('cannot reschedule a past appointment (422 on starts_at)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = Appointment::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertSessionHasErrors('starts_at');
});

// ---------------------------------------------------------------------------
// PUT /appointments/{appointment} — authorization
// ---------------------------------------------------------------------------

it('assistant gets 403 on PUT /appointments/{appointment} (no appointments.update permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    raRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($assistant)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertForbidden();
});

it('doctor can reschedule their own appointment to a new slot', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $doctorUser = User::factory()->create();
    raRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($doctorUser)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Rescheduled);
});

it('doctor without assignDoctor cannot set a different doctor_id on update (422 on doctor_id)', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $doctorUser = User::factory()->create();
    raRole($doctorUser, 'doctor', $clinic->id);
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $ownDoctor, $patient);

    $this->actingAs($doctorUser)
        ->put(route('appointments.update', $appointment), raPayload($otherDoctor->id, raMondayAt('11:00')))
        ->assertForbidden();
});

it('receptionist (has assignDoctor) can reschedule any appointment', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $receptionist = User::factory()->create();
    raRole($receptionist, 'receptionist', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($receptionist)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertRedirect(route('appointments.index'));
});

it('successful reschedule flashes a toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    raRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = raAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), raPayload($doctor->id, raMondayAt('11:00')))
        ->assertRedirect(route('appointments.index'))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user gets 404 on PUT for a clinic B appointment (multi-tenant isolation)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    raRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $appointmentB = raAppointment($clinicB, $doctorB, $patientB);

    $originalStatus = $appointmentB->status;

    $this->actingAs($ownerA)
        ->put(route('appointments.update', $appointmentB), raPayload($doctorB->id, raMondayAt('11:00')))
        ->assertNotFound();

    // Clinic B's appointment is untouched.
    $appointmentB->refresh();
    expect($appointmentB->status)->toBe($originalStatus);
});
