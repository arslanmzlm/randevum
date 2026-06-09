<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
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
function cxRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Create a future appointment with a given status (Confirmed by default).
 *
 * @param  array<string, mixed>  $overrides
 */
function cxAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
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
// PATCH /appointments/{appointment}/cancel — authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from PATCH /appointments/{appointment}/cancel', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient);

    $this->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect(route('login'));
});

it('assistant gets 403 on PATCH /appointments/{appointment}/cancel (no appointments.cancel permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    cxRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient);

    $this->actingAs($assistant)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertForbidden();
});

it('owner can cancel any appointment (has appointments.viewAll)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Cancelled);
});

it('doctor can cancel their own appointment', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    cxRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient);

    $this->actingAs($doctorUser)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Cancelled);
});

it('doctor cannot cancel another doctor appointment (403 via policy ownership)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    cxRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// PATCH /appointments/{appointment}/cancel — core cancel behavior
// ---------------------------------------------------------------------------

it('cancel transitions a Confirmed appointment to Cancelled and writes status_log', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Confirmed]);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Cancelled);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'cancelled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('confirmed')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('cancel transitions a Rescheduled appointment to Cancelled and writes status_log', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Rescheduled]);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Cancelled);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'cancelled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('rescheduled');
});

it('cancel transitions an Arrived appointment to Cancelled and writes status_log', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Arrived]);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect(route('appointments.index'));

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Cancelled);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'cancelled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('arrived');
});

it('cancel persists an optional reason in the status_log', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient);

    $reason = 'Hasta iptal etti';

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), ['reason' => $reason])
        ->assertRedirect(route('appointments.index'));

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'cancelled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->reason)->toBe($reason);
});

it('cancel without a reason stores null reason in the status_log', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect(route('appointments.index'));

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'cancelled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->reason)->toBeNull();
});

it('cannot cancel a Completed appointment (422 on status)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Completed]);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertSessionHasErrors('status');

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Completed);
});

it('cannot cancel an already-Cancelled appointment (422 on status)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Cancelled]);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertSessionHasErrors('status');
});

it('reason validation rejects a reason longer than 500 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), ['reason' => str_repeat('a', 501)])
        ->assertSessionHasErrors('reason');
});

it('successful cancel flashes a toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cxRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = cxAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect(route('appointments.index'))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user gets 404 on PATCH cancel for a clinic B appointment (multi-tenant isolation)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    cxRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $appointmentB = cxAppointment($clinicB, $doctorB, $patientB);

    $this->actingAs($ownerA)
        ->patch(route('appointments.cancel', $appointmentB), [])
        ->assertNotFound();

    // Clinic B's appointment status must be untouched.
    $appointmentB->refresh();
    expect($appointmentB->status)->toBe(AppointmentStatus::Confirmed);
});
