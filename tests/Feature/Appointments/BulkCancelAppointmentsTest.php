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
function bkRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Create an appointment starting at 10:00 clinic-local on the given Y-m-d date.
 *
 * @param  array<string, mixed>  $overrides
 */
function bkAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, string $date, array $overrides = []): Appointment
{
    $startsAt = Carbon::createFromFormat('Y-m-d', $date, $clinic->timezone)->setTime(10, 0, 0)->utc();

    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ], $overrides));
}

/**
 * Tomorrow's date in Istanbul timezone (Y-m-d).
 */
function bkTargetDate(): string
{
    return Carbon::tomorrow('Europe/Istanbul')->format('Y-m-d');
}

/**
 * Valid POST /appointments/bulk-cancel payload for the target date.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function bkPayload(array $overrides = []): array
{
    return array_merge([
        'start_date' => bkTargetDate(),
        'end_date' => bkTargetDate(),
        'doctor_id' => null,
        'reason' => null,
        'block_new_bookings' => false,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Authorization — GET /appointments/bulk-cancel (form)
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /appointments/bulk-cancel', function (): void {
    $this->get(route('appointments.bulk-cancel'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /appointments/bulk-cancel and the BulkCancel component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.bulk-cancel'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/BulkCancel')
            ->has('doctors')
            ->has('timezone')
            ->has('ownDoctorId')
        );
});

it('manager can access GET /appointments/bulk-cancel', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    bkRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('appointments.bulk-cancel'))
        ->assertOk();
});

it('receptionist gets 403 on GET /appointments/bulk-cancel (no appointments.bulkCancel)', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    bkRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('appointments.bulk-cancel'))
        ->assertForbidden();
});

it('doctor gets 403 on GET /appointments/bulk-cancel', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    bkRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('appointments.bulk-cancel'))
        ->assertForbidden();
});

it('assistant gets 403 on GET /appointments/bulk-cancel', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    bkRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('appointments.bulk-cancel'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Authorization — POST /appointments/bulk-cancel (execute)
// ---------------------------------------------------------------------------

it('guest is redirected to login from POST /appointments/bulk-cancel', function (): void {
    $this->post(route('appointments.bulk-cancel.store'), bkPayload())
        ->assertRedirect(route('login'));
});

it('receptionist gets 403 on POST /appointments/bulk-cancel', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    bkRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('appointments.bulk-cancel.store'), bkPayload())
        ->assertForbidden();
});

it('doctor gets 403 on POST /appointments/bulk-cancel', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    bkRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('appointments.bulk-cancel.store'), bkPayload())
        ->assertForbidden();
});

it('assistant gets 403 on POST /appointments/bulk-cancel', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    bkRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('appointments.bulk-cancel.store'), bkPayload())
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// POST /appointments/bulk-cancel — core cancel behavior
// ---------------------------------------------------------------------------

it('cancels all Confirmed and Rescheduled appointments in the date range', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $confirmed = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Confirmed]);
    $rescheduled = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Rescheduled]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload(['reason' => 'Doktor hastalandı']))
        ->assertRedirect(route('appointments.index'));

    $confirmed->refresh();
    $rescheduled->refresh();

    expect($confirmed->status)->toBe(AppointmentStatus::Cancelled)
        ->and($rescheduled->status)->toBe(AppointmentStatus::Cancelled);
});

it('writes a status_log row for each cancelled appointment with the shared reason and actor', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $confirmed = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Confirmed]);
    $rescheduled = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Rescheduled]);
    $reason = 'Klinik kapalı';

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload(['reason' => $reason]));

    foreach ([$confirmed->id, $rescheduled->id] as $appointmentId) {
        $log = StatusLog::withoutGlobalScopes()
            ->where('loggable_type', 'appointment')
            ->where('loggable_id', $appointmentId)
            ->where('to_status', 'cancelled')
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->by_user_id)->toBe($owner->id)
            ->and($log->reason)->toBe($reason);
    }
});

it('status_log records the correct from_status for each appointment', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $confirmed = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Confirmed]);
    $rescheduled = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Rescheduled]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload());

    $confirmedLog = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $confirmed->id)
        ->where('to_status', 'cancelled')
        ->first();

    $rescheduledLog = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $rescheduled->id)
        ->where('to_status', 'cancelled')
        ->first();

    expect($confirmedLog->from_status)->toBe('confirmed')
        ->and($rescheduledLog->from_status)->toBe('rescheduled');
});

it('leaves Arrived appointments untouched (excluded from bulk-cancellable set)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $arrived = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Arrived]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload());

    $arrived->refresh();
    expect($arrived->status)->toBe(AppointmentStatus::Arrived);
});

it('leaves Completed appointments untouched', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $completed = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Completed]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload());

    $completed->refresh();
    expect($completed->status)->toBe(AppointmentStatus::Completed);
});

it('leaves already-Cancelled appointments untouched', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $alreadyCancelled = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Cancelled]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload());

    $alreadyCancelled->refresh();
    expect($alreadyCancelled->status)->toBe(AppointmentStatus::Cancelled);
});

it('leaves out-of-range appointments untouched when start falls outside the window', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Two days ahead — outside the single-day window.
    $outsideDate = Carbon::tomorrow('Europe/Istanbul')->addDay()->format('Y-m-d');
    $outside = bkAppointment($clinic, $doctor, $patient, $outsideDate);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload());

    $outside->refresh();
    expect($outside->status)->toBe(AppointmentStatus::Confirmed);
});

it('narrows the cancellation set to a specific doctor when doctor_id is provided', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $apptA = bkAppointment($clinic, $doctorA, $patient, bkTargetDate());
    $apptB = bkAppointment($clinic, $doctorB, $patient, bkTargetDate());

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload(['doctor_id' => $doctorA->id]));

    $apptA->refresh();
    $apptB->refresh();

    expect($apptA->status)->toBe(AppointmentStatus::Cancelled)
        ->and($apptB->status)->toBe(AppointmentStatus::Confirmed);
});

it('returns success with count=0 and redirects when no matching appointments exist', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload())
        ->assertRedirect(route('appointments.index'));
});

it('flashes a success toast after bulk cancel', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload())
        ->assertRedirect(route('appointments.index'))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('rejects end_date before start_date with 422', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), [
            'start_date' => bkTargetDate(),
            'end_date' => Carbon::yesterday()->format('Y-m-d'),
        ])
        ->assertSessionHasErrors('end_date');
});

it('rejects a range greater than 31 days with 422 on end_date', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    $start = Carbon::tomorrow('Europe/Istanbul')->format('Y-m-d');
    $end = Carbon::tomorrow('Europe/Istanbul')->addDays(32)->format('Y-m-d');

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), [
            'start_date' => $start,
            'end_date' => $end,
        ])
        ->assertSessionHasErrors('end_date');
});

it('rejects reason longer than 500 characters with 422', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload([
            'reason' => str_repeat('a', 501),
        ]))
        ->assertSessionHasErrors('reason');
});

it('rejects missing start_date with 422', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), ['end_date' => bkTargetDate()])
        ->assertSessionHasErrors('start_date');
});

// ---------------------------------------------------------------------------
// block_new_bookings
// ---------------------------------------------------------------------------

it('creates a schedule_exception for the doctor scope when block_new_bookings is set with a single doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    bkAppointment($clinic, $doctor, $patient, bkTargetDate());

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload([
            'doctor_id' => $doctor->id,
            'block_new_bookings' => true,
            'reason' => 'Doktor hastalandı',
        ]));

    $exception = ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('doctor_id', $doctor->id)
        ->where('is_all_day', true)
        ->first();

    expect($exception)->not->toBeNull()
        ->and($exception->reason)->toBe('Doktor hastalandı');
});

it('creates one schedule_exception per active clinic doctor when block_new_bookings is set with clinic scope', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id, 'is_active' => true]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id, 'is_active' => true]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // An appointment must exist so the service doesn't short-circuit.
    bkAppointment($clinic, $doctorA, $patient, bkTargetDate());

    // No doctor_id + viewAll = clinic scope → one exception per active doctor.
    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload([
            'block_new_bookings' => true,
        ]));

    $exceptionCount = ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('is_all_day', true)
        ->count();

    expect($exceptionCount)->toBe(2);
});

it('does not create a schedule_exception when block_new_bookings is false', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    bkAppointment($clinic, $doctor, $patient, bkTargetDate());

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload(['block_new_bookings' => false]));

    $exceptionCount = ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->count();

    expect($exceptionCount)->toBe(0);
});

it('does not create a schedule_exception when block_new_bookings is set but no appointments matched', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    // No appointments at all — the service short-circuits before the transaction.
    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel.store'), bkPayload(['block_new_bookings' => true]));

    $exceptionCount = ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->count();

    expect($exceptionCount)->toBe(0);
});

// ---------------------------------------------------------------------------
// Doctor visibility scope (users without appointments.viewAll)
// ---------------------------------------------------------------------------

it('a user without appointments.viewAll only cancels their own doctor\'s appointments', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUserA = User::factory()->create();
    // doctor role provides the clinic context (resolveClinicId); direct grant adds
    // bulkCancel without viewAll to test the service's implicit doctor-scope logic.
    bkRole($doctorUserA, 'doctor', $clinic->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $doctorUserA->givePermissionTo('appointments.bulkCancel');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $doctorUserA->unsetRelation('roles');
    $doctorUserA->unsetRelation('permissions');

    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $apptA = bkAppointment($clinic, $doctorA, $patient, bkTargetDate());
    $apptB = bkAppointment($clinic, $doctorB, $patient, bkTargetDate());

    $this->actingAs($doctorUserA)
        ->post(route('appointments.bulk-cancel.store'), bkPayload());

    $apptA->refresh();
    $apptB->refresh();

    expect($apptA->status)->toBe(AppointmentStatus::Cancelled)
        ->and($apptB->status)->toBe(AppointmentStatus::Confirmed);
});

it('a user without appointments.viewAll and no doctor profile cancels nothing', function (): void {
    $clinic = Clinic::factory()->create();
    // doctor role provides the clinic context; direct bulkCancel grant without viewAll.
    // No Doctor profile row → service resolves empty doctorIds → zero cancellations.
    $doctorUser = User::factory()->create();
    bkRole($doctorUser, 'doctor', $clinic->id);
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $doctorUser->givePermissionTo('appointments.bulkCancel');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $doctorUser->unsetRelation('roles');
    $doctorUser->unsetRelation('permissions');

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $appt = bkAppointment($clinic, $otherDoctor, $patient, bkTargetDate());

    $this->actingAs($doctorUser)
        ->post(route('appointments.bulk-cancel.store'), bkPayload())
        ->assertRedirect(route('appointments.index'));

    $appt->refresh();
    expect($appt->status)->toBe(AppointmentStatus::Confirmed);
});

// ---------------------------------------------------------------------------
// GET /appointments/bulk-cancel/preview
// ---------------------------------------------------------------------------

it('preview returns the correct count and appointment rows for given criteria', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Confirmed]);
    bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Rescheduled]);

    $this->actingAs($owner)
        ->getJson(route('appointments.bulk-cancel.preview', [
            'start_date' => bkTargetDate(),
            'end_date' => bkTargetDate(),
        ]))
        ->assertOk()
        ->assertJson(['count' => 2])
        ->assertJsonCount(2, 'appointments');
});

it('preview excludes Arrived, Completed, and Cancelled statuses', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Arrived]);
    bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Completed]);
    bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Cancelled]);
    $eligible = bkAppointment($clinic, $doctor, $patient, bkTargetDate(), ['status' => AppointmentStatus::Confirmed]);

    $response = $this->actingAs($owner)
        ->getJson(route('appointments.bulk-cancel.preview', [
            'start_date' => bkTargetDate(),
            'end_date' => bkTargetDate(),
        ]))
        ->assertOk();

    $response->assertJson(['count' => 1]);
    $response->assertJsonPath('appointments.0.id', $eligible->id);
});

it('preview rejects a range greater than 31 days with 422', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bkRole($owner, 'owner', $clinic->id);

    $start = Carbon::tomorrow('Europe/Istanbul')->format('Y-m-d');
    $end = Carbon::tomorrow('Europe/Istanbul')->addDays(32)->format('Y-m-d');

    $this->actingAs($owner)
        ->getJson(route('appointments.bulk-cancel.preview', [
            'start_date' => $start,
            'end_date' => $end,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');
});

it('preview returns 403 for receptionist (no appointments.bulkCancel)', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    bkRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->getJson(route('appointments.bulk-cancel.preview', [
            'start_date' => bkTargetDate(),
            'end_date' => bkTargetDate(),
        ]))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic-A bulk cancel never touches clinic-B appointments in the same date range', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    bkRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $apptA = bkAppointment($clinicA, $doctorA, $patientA, bkTargetDate());
    $apptB = bkAppointment($clinicB, $doctorB, $patientB, bkTargetDate());

    $this->actingAs($ownerA)
        ->post(route('appointments.bulk-cancel.store'), bkPayload());

    $apptA->refresh();
    $apptB->refresh();

    expect($apptA->status)->toBe(AppointmentStatus::Cancelled)
        ->and($apptB->status)->toBe(AppointmentStatus::Confirmed);
});

it('clinic-A preview never lists clinic-B appointments in the same date range', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    bkRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $apptA = bkAppointment($clinicA, $doctorA, $patientA, bkTargetDate());
    bkAppointment($clinicB, $doctorB, $patientB, bkTargetDate());

    $response = $this->actingAs($ownerA)
        ->getJson(route('appointments.bulk-cancel.preview', [
            'start_date' => bkTargetDate(),
            'end_date' => bkTargetDate(),
        ]))
        ->assertOk();

    $response->assertJson(['count' => 1]);
    $response->assertJsonPath('appointments.0.id', $apptA->id);
});

it('status_logs from clinic-A bulk cancel do not reference clinic-B appointments', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    bkRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $apptA = bkAppointment($clinicA, $doctorA, $patientA, bkTargetDate());
    $apptB = bkAppointment($clinicB, $doctorB, $patientB, bkTargetDate());

    $this->actingAs($ownerA)
        ->post(route('appointments.bulk-cancel.store'), bkPayload());

    $clinicACancelLogged = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $apptA->id)
        ->where('to_status', 'cancelled')
        ->exists();

    $clinicBCancelLogged = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $apptB->id)
        ->where('to_status', 'cancelled')
        ->exists();

    expect($clinicACancelLogged)->toBeTrue()
        ->and($clinicBCancelLogged)->toBeFalse();
});
