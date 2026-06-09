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
function daRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Create a future Confirmed appointment (hard-delete eligible by default).
 *
 * @param  array<string, mixed>  $overrides
 */
function daAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
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
// DELETE /appointments/{appointment} — authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from DELETE /appointments/{appointment}', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient);

    $this->delete(route('appointments.destroy', $appointment))
        ->assertRedirect(route('login'));
});

it('owner can delete a future Confirmed appointment', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    daRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient);
    $appointmentId = $appointment->id;

    $this->actingAs($owner)
        ->delete(route('appointments.destroy', $appointment))
        ->assertRedirect(route('appointments.index'));

    // Must be completely gone — no soft-delete trace.
    expect(Appointment::withoutGlobalScopes()->withTrashed()->find($appointmentId))->toBeNull();
});

it('manager can delete a future Confirmed appointment', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    daRole($manager, 'manager', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient);
    $appointmentId = $appointment->id;

    $this->actingAs($manager)
        ->delete(route('appointments.destroy', $appointment))
        ->assertRedirect(route('appointments.index'));

    expect(Appointment::withoutGlobalScopes()->withTrashed()->find($appointmentId))->toBeNull();
});

it('receptionist gets 403 on DELETE /appointments/{appointment} (no appointments.delete permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    daRole($receptionist, 'receptionist', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient);

    $this->actingAs($receptionist)
        ->delete(route('appointments.destroy', $appointment))
        ->assertForbidden();
});

it('doctor gets 403 on DELETE /appointments/{appointment} (no appointments.delete permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    daRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient);

    $this->actingAs($doctorUser)
        ->delete(route('appointments.destroy', $appointment))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// DELETE /appointments/{appointment} — deletion rules
// ---------------------------------------------------------------------------

it('hard-delete leaves no DB trace (not soft-deleted, gone from withTrashed)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    daRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient);
    $appointmentId = $appointment->id;

    $this->actingAs($owner)
        ->delete(route('appointments.destroy', $appointment))
        ->assertRedirect(route('appointments.index'));

    // Neither live nor trashed — it is a hard delete.
    expect(Appointment::withoutGlobalScopes()->withTrashed()->find($appointmentId))->toBeNull();
});

it('no status_log row is written when deleting (record ceases to exist)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    daRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient);
    $appointmentId = $appointment->id;

    $logCountBefore = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointmentId)
        ->count();

    $this->actingAs($owner)
        ->delete(route('appointments.destroy', $appointment))
        ->assertRedirect(route('appointments.index'));

    $logCountAfter = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointmentId)
        ->count();

    // No new status_log — hard delete leaves no audit trail.
    expect($logCountAfter)->toBe($logCountBefore);
});

it('rejects deletion of a Rescheduled appointment (422 on appointment)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    daRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Rescheduled]);
    $appointmentId = $appointment->id;

    $this->actingAs($owner)
        ->delete(route('appointments.destroy', $appointment))
        ->assertSessionHasErrors('appointment');

    // Row must still exist.
    expect(Appointment::withoutGlobalScopes()->find($appointmentId))->not->toBeNull();
});

it('rejects deletion of a Completed appointment (422 on appointment)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    daRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Completed]);

    $this->actingAs($owner)
        ->delete(route('appointments.destroy', $appointment))
        ->assertSessionHasErrors('appointment');
});

it('rejects deletion of a past Confirmed appointment (422 on appointment — must cancel instead)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    daRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Past appointment — starts_at has already passed.
    $appointment = Appointment::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->delete(route('appointments.destroy', $appointment))
        ->assertSessionHasErrors('appointment');
});

it('successful delete redirects to appointments index and flashes toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    daRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = daAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->delete(route('appointments.destroy', $appointment))
        ->assertRedirect(route('appointments.index'))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user gets 404 on DELETE for a clinic B appointment (multi-tenant isolation)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    daRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $appointmentB = daAppointment($clinicB, $doctorB, $patientB);

    $this->actingAs($ownerA)
        ->delete(route('appointments.destroy', $appointmentB))
        ->assertNotFound();

    // Clinic B's appointment is untouched.
    expect(Appointment::withoutGlobalScopes()->withTrashed()->find($appointmentB->id))->not->toBeNull();
});
