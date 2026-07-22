<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\StatusLog;
use App\Models\User;
use App\Modules\Scheduling\Services\AppointmentService;
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
function nsmRole(User $user, string $role, int $clinicId): void
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
function nsmAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
{
    $mondayUtc = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0)->utc();

    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $mondayUtc,
        'ends_at' => $mondayUtc->copy()->addMinutes(30),
        'is_walk_in' => false,
    ], $overrides));
}

/**
 * A clinic + owner + a doctor (user) + patient, ready to act on appointments.
 *
 * @return array{clinic: Clinic, owner: User, doctorUser: User, doctor: Doctor, patient: Patient}
 */
function nsmSetup(array $clinicOverrides = []): array
{
    $clinic = Clinic::factory()->create($clinicOverrides);
    $owner = User::factory()->create();
    nsmRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'doctorUser', 'doctor', 'patient');
}

function nsmStatusLogFor(Appointment $appointment, string $toStatus): ?StatusLog
{
    return StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', $toStatus)
        ->latest('id')
        ->first();
}

// ---------------------------------------------------------------------------
// PATCH /appointments/{appointment}/arrive — authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from PATCH /appointments/{appointment}/arrive', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->patch(route('appointments.arrive', $appointment))
        ->assertRedirect(route('login'));
});

it('a user with no clinic role gets 403 on PATCH /appointments/{appointment}/arrive', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $noRole = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $noRole->assignRole('patient');
    $noRole->unsetRelation('roles');
    $noRole->unsetRelation('permissions');

    $this->actingAs($noRole)
        ->patch(route('appointments.arrive', $appointment))
        ->assertForbidden();
});

it('owner can check in any appointment (has appointments.viewAll)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.arrive', $appointment))
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Arrived);
});

it('assistant can check in any appointment (has appointments.viewAll)', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $assistant = User::factory()->create();
    nsmRole($assistant, 'assistant', $clinic->id);
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->actingAs($assistant)
        ->patch(route('appointments.arrive', $appointment))
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Arrived);
});

it('doctor can check in their own appointment', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    nsmRole($doctorUser, 'doctor', $clinic->id);
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->actingAs($doctorUser)
        ->patch(route('appointments.arrive', $appointment))
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Arrived);
});

it('doctor cannot check in another doctor appointment (403 via policy ownership)', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'patient' => $patient] = nsmSetup();
    nsmRole($doctorUser, 'doctor', $clinic->id);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $appointment = nsmAppointment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->patch(route('appointments.arrive', $appointment))
        ->assertForbidden();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);
});

// ---------------------------------------------------------------------------
// PATCH /appointments/{appointment}/arrive — core check-in behavior
// ---------------------------------------------------------------------------

it('check-in transitions a Confirmed appointment to Arrived and writes a status_log with the actor', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Confirmed]);

    $this->actingAs($owner)
        ->patch(route('appointments.arrive', $appointment))
        ->assertRedirect()
        ->assertSessionHas('toasts');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Arrived);

    $log = nsmStatusLogFor($appointment, 'arrived');
    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('confirmed')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('check-in transitions a Rescheduled appointment to Arrived', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Rescheduled]);

    $this->actingAs($owner)
        ->patch(route('appointments.arrive', $appointment))
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Arrived);

    $log = nsmStatusLogFor($appointment, 'arrived');
    expect($log)->not->toBeNull()->and($log->from_status)->toBe('rescheduled');
});

it('check-in on an already-Arrived appointment is a no-op and writes no extra log', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Arrived]);

    $before = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')->where('loggable_id', $appointment->id)->count();

    $this->actingAs($owner)
        ->patch(route('appointments.arrive', $appointment))
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Arrived);

    $after = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')->where('loggable_id', $appointment->id)->count();
    expect($after)->toBe($before);
});

it('cannot check in a Completed appointment (422 on status)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Completed]);

    $this->actingAs($owner)
        ->patch(route('appointments.arrive', $appointment))
        ->assertSessionHasErrors('status');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Completed);
});

it('cannot check in a Cancelled appointment (422 on status)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Cancelled]);

    $this->actingAs($owner)
        ->patch(route('appointments.arrive', $appointment))
        ->assertSessionHasErrors('status');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('cannot check in a NoShow appointment (422 on status)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::NoShow]);

    $this->actingAs($owner)
        ->patch(route('appointments.arrive', $appointment))
        ->assertSessionHasErrors('status');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow);
});

// ---------------------------------------------------------------------------
// PATCH /appointments/{appointment}/no-show — authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from PATCH /appointments/{appointment}/no-show', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->patch(route('appointments.no-show', $appointment), [])
        ->assertRedirect(route('login'));
});

it('a user with no clinic role gets 403 on PATCH /appointments/{appointment}/no-show', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $noRole = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $noRole->assignRole('patient');
    $noRole->unsetRelation('roles');
    $noRole->unsetRelation('permissions');

    $this->actingAs($noRole)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertForbidden();
});

it('receptionist can mark any appointment no-show (has appointments.viewAll)', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $receptionist = User::factory()->create();
    nsmRole($receptionist, 'receptionist', $clinic->id);
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->actingAs($receptionist)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow);
});

it('doctor can mark their own appointment no-show', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    nsmRole($doctorUser, 'doctor', $clinic->id);
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->actingAs($doctorUser)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow);
});

it('doctor cannot mark another doctor appointment no-show (403 via policy ownership)', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'patient' => $patient] = nsmSetup();
    nsmRole($doctorUser, 'doctor', $clinic->id);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $appointment = nsmAppointment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertForbidden();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);
});

// ---------------------------------------------------------------------------
// PATCH /appointments/{appointment}/no-show — core behavior
// ---------------------------------------------------------------------------

it('manual no-show transitions a Confirmed appointment to NoShow and logs the actor', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Confirmed]);

    $this->actingAs($owner)
        ->patch(route('appointments.no-show', $appointment), ['reason' => 'Hasta gelmedi'])
        ->assertRedirect()
        ->assertSessionHas('toasts');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow);

    $log = nsmStatusLogFor($appointment, 'no_show');
    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('confirmed')
        ->and($log->by_user_id)->toBe($owner->id)
        ->and($log->reason)->toBe('Hasta gelmedi');
});

it('manual no-show transitions a Rescheduled appointment to NoShow', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Rescheduled]);

    $this->actingAs($owner)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertRedirect();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow);

    $log = nsmStatusLogFor($appointment, 'no_show');
    expect($log)->not->toBeNull()->and($log->from_status)->toBe('rescheduled');
});

it('manual no-show without a reason stores a null reason', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertRedirect();

    $log = nsmStatusLogFor($appointment, 'no_show');
    expect($log->reason)->toBeNull();
});

it('reason validation rejects a reason longer than 500 characters', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.no-show', $appointment), ['reason' => str_repeat('a', 501)])
        ->assertSessionHasErrors('reason');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('cannot mark an Arrived appointment no-show (422 on status) — checked-in patients are excluded', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Arrived]);

    $this->actingAs($owner)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertSessionHasErrors('status');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Arrived);
});

it('cannot mark a Completed appointment no-show (422 on status)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Completed]);

    $this->actingAs($owner)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertSessionHasErrors('status');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Completed);
});

it('cannot mark an already-Cancelled appointment no-show (422 on status)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Cancelled]);

    $this->actingAs($owner)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertSessionHasErrors('status');

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Cancelled);
});

it('cannot mark an already-NoShow appointment no-show again (422 on status)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup();
    $appointment = nsmAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::NoShow]);

    $this->actingAs($owner)
        ->patch(route('appointments.no-show', $appointment), [])
        ->assertSessionHasErrors('status');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user gets 404 on PATCH arrive for a clinic B appointment (multi-tenant isolation)', function (): void {
    ['owner' => $ownerA] = nsmSetup();
    ['clinic' => $clinicB, 'doctor' => $doctorB, 'patient' => $patientB] = nsmSetup();
    $appointmentB = nsmAppointment($clinicB, $doctorB, $patientB);

    $this->actingAs($ownerA)
        ->patch(route('appointments.arrive', $appointmentB))
        ->assertNotFound();

    expect($appointmentB->refresh()->status)->toBe(AppointmentStatus::Confirmed);
});

it('clinic A user gets 404 on PATCH no-show for a clinic B appointment (multi-tenant isolation)', function (): void {
    ['owner' => $ownerA] = nsmSetup();
    ['clinic' => $clinicB, 'doctor' => $doctorB, 'patient' => $patientB] = nsmSetup();
    $appointmentB = nsmAppointment($clinicB, $doctorB, $patientB);

    $this->actingAs($ownerA)
        ->patch(route('appointments.no-show', $appointmentB), [])
        ->assertNotFound();

    expect($appointmentB->refresh()->status)->toBe(AppointmentStatus::Confirmed);
});

// ---------------------------------------------------------------------------
// Auto no-show sweep — `appointments:auto-no-show` command
// ---------------------------------------------------------------------------

it('sweeps a past untouched Confirmed appointment past the grace cutoff to NoShow', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    // ends_at 3h ago — past the 2h grace cutoff.
    $endsAt = $now->copy()->subHours(3);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow);

    $log = nsmStatusLogFor($appointment, 'no_show');
    expect($log)->not->toBeNull()
        ->and($log->by_user_id)->toBeNull()
        ->and($log->reason)->toBe('auto');

    Carbon::setTestNow();
});

it('sweeps a past untouched Rescheduled appointment to NoShow', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $endsAt = $now->copy()->subHours(3);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Rescheduled,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow);

    Carbon::setTestNow();
});

it('does not sweep a Confirmed appointment still inside the grace window', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    // ends_at 1h ago — still inside the 2h grace window.
    $endsAt = $now->copy()->subHour();
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);

    Carbon::setTestNow();
});

it('does not sweep a future Confirmed appointment', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $now->copy()->addDay(),
        'ends_at' => $now->copy()->addDay()->addMinutes(30),
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);

    Carbon::setTestNow();
});

it('does not sweep a past walk-in appointment even when untouched', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $endsAt = $now->copy()->subHours(3);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => true,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);

    Carbon::setTestNow();
});

it('does not sweep a past Arrived appointment (patient was physically present)', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $endsAt = $now->copy()->subHours(3);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Arrived,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Arrived);

    Carbon::setTestNow();
});

it('does not sweep a past Completed appointment', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $endsAt = $now->copy()->subHours(3);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Completed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Completed);

    Carbon::setTestNow();
});

it('does not sweep any appointment for a clinic with auto_no_show_enabled = false', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => false]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $endsAt = $now->copy()->subDays(2);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::Confirmed);

    Carbon::setTestNow();
});

it('running the sweep twice does not re-touch an already-NoShow appointment (idempotent)', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $endsAt = $now->copy()->subHours(3);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();
    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointment->refresh()->status)->toBe(AppointmentStatus::NoShow);

    $logCount = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('to_status', 'no_show')
        ->count();

    expect($logCount)->toBe(1);

    Carbon::setTestNow();
});

it('the command reports the total swept count', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'patient' => $patient] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    $endsAt = $now->copy()->subHours(3);
    Appointment::factory()->count(2)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')
        ->expectsOutputToContain('2')
        ->assertSuccessful();

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// Auto sweep — multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('the sweep only touches the iterated clinic\'s rows and honors each clinic\'s own toggle', function (): void {
    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');
    Carbon::setTestNow($now);

    // Clinic A: enabled — its overdue appointment must sweep.
    ['clinic' => $clinicA, 'doctor' => $doctorA, 'patient' => $patientA] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);
    $endsAt = $now->copy()->subHours(3);
    $appointmentA = Appointment::factory()->create([
        'clinic_id' => $clinicA->id,
        'doctor_id' => $doctorA->id,
        'patient_id' => $patientA->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    // Clinic B: toggle OFF — its equally-overdue appointment must NOT sweep.
    ['clinic' => $clinicB, 'doctor' => $doctorB, 'patient' => $patientB] = nsmSetup(['auto_no_show_enabled' => false]);
    $appointmentB = Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30),
        'ends_at' => $endsAt,
        'is_walk_in' => false,
    ]);

    $this->artisan('appointments:auto-no-show')->assertSuccessful();

    expect($appointmentA->refresh()->status)->toBe(AppointmentStatus::NoShow)
        ->and($appointmentB->refresh()->status)->toBe(AppointmentStatus::Confirmed);

    Carbon::setTestNow();
});

// ---------------------------------------------------------------------------
// AppointmentService::autoMarkNoShows — unit-level return value
// ---------------------------------------------------------------------------

it('autoMarkNoShows returns the total number of appointments swept across clinics', function (): void {
    $now = Carbon::parse('2026-06-20 12:00:00', 'UTC');

    ['clinic' => $clinicA, 'doctor' => $doctorA, 'patient' => $patientA] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);
    ['clinic' => $clinicB, 'doctor' => $doctorB, 'patient' => $patientB] = nsmSetup(['auto_no_show_enabled' => true, 'auto_no_show_grace_hours' => 2]);

    $endsAt = $now->copy()->subHours(3);

    Appointment::factory()->create([
        'clinic_id' => $clinicA->id, 'doctor_id' => $doctorA->id, 'patient_id' => $patientA->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30), 'ends_at' => $endsAt, 'is_walk_in' => false,
    ]);
    Appointment::factory()->count(2)->create([
        'clinic_id' => $clinicB->id, 'doctor_id' => $doctorB->id, 'patient_id' => $patientB->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $endsAt->copy()->subMinutes(30), 'ends_at' => $endsAt, 'is_walk_in' => false,
    ]);

    $count = app(AppointmentService::class)->autoMarkNoShows($now);

    expect($count)->toBe(3);
});
