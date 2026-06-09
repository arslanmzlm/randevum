<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleException;
use App\Models\Service;
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
 * Assign a clinic-scoped Spatie Teams role.
 */
function chkRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Next Monday at 10:00 in Europe/Istanbul (within default working hours, outside break).
 */
function chkMondaySlot(): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 10:00:00';
}

/**
 * Build a minimal valid GET /appointments/availability query param array.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chkParams(int $doctorId, array $overrides = []): array
{
    return array_merge([
        'doctor_id' => $doctorId,
        'starts_at' => chkMondaySlot(),
        'duration_minutes' => 30,
        'service_id' => null,
        'is_walk_in' => false,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('returns 401 for unauthenticated requests to availability endpoint', function (): void {
    $this->getJson(route('appointments.availability', ['doctor_id' => 1, 'starts_at' => chkMondaySlot()]))
        ->assertUnauthorized();
});

it('returns 403 for assistant who lacks appointments.create', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    chkRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($assistant)
        ->getJson(route('appointments.availability', chkParams($doctor->id)))
        ->assertForbidden();
});

it('owner can access the availability endpoint', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id)))
        ->assertOk();
});

it('receptionist can access the availability endpoint', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $receptionist = User::factory()->create();
    chkRole($receptionist, 'receptionist', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($receptionist)
        ->getJson(route('appointments.availability', chkParams($doctor->id)))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Validation (FormRequest)
// ---------------------------------------------------------------------------

it('returns 422 when doctor_id is missing', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', ['starts_at' => chkMondaySlot()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');
});

it('returns 422 when starts_at is missing', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', ['doctor_id' => $doctor->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');
});

it('returns 422 when starts_at is not a valid date string', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', ['doctor_id' => $doctor->id, 'starts_at' => 'not-a-date']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('starts_at');
});

it('returns 422 when duration_minutes is below 5', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, ['duration_minutes' => 4])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duration_minutes');
});

it('returns 422 when duration_minutes exceeds 480', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, ['duration_minutes' => 481])))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('duration_minutes');
});

// ---------------------------------------------------------------------------
// Layer 1 — working hours
// ---------------------------------------------------------------------------

it('returns available:true, reason:null for a clean in-hours slot', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id)))
        ->assertOk()
        ->assertJson(['available' => true, 'reason' => null]);
});

it('returns available:false, reason:outside_hours for a slot before opening time', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $earlySlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 08:00:00';

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, ['starts_at' => $earlySlot])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'outside_hours']);
});

it('returns available:false, reason:outside_hours for a slot inside the break window', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $breakSlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 12:15:00';

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, ['starts_at' => $breakSlot])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'outside_hours']);
});

it('returns available:false, reason:outside_hours for a slot extending past closing time', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    // 19:00 + 30 min extends past closing (19:00)
    $lateSlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 19:00:00';

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => $lateSlot,
            'duration_minutes' => 30,
        ])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'outside_hours']);
});

// ---------------------------------------------------------------------------
// Layer 2 — schedule exceptions
// ---------------------------------------------------------------------------

it('returns available:false, reason:exception when a schedule exception overlaps the slot', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
    ]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'is_walk_in' => false,
        ])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'exception']);
});

// ---------------------------------------------------------------------------
// Layer 3 — appointment conflicts
// ---------------------------------------------------------------------------

it('returns available:false, reason:conflict when a Confirmed appointment overlaps the slot', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'conflict']);
});

it('returns available:false, reason:conflict when an Arrived appointment overlaps the slot', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    Appointment::factory()->withStatus(AppointmentStatus::Arrived)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
    ]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'conflict']);
});

// ---------------------------------------------------------------------------
// Walk-in bypass (layers 2 & 3)
// ---------------------------------------------------------------------------

it('walk-in over a schedule exception in hours returns available:true', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
    ]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'is_walk_in' => true,
        ])))
        ->assertOk()
        ->assertJson(['available' => true, 'reason' => null]);
});

it('walk-in over a Confirmed appointment in hours returns available:true', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'is_walk_in' => true,
        ])))
        ->assertOk()
        ->assertJson(['available' => true, 'reason' => null]);
});

it('walk-in outside working hours still returns available:false, reason:outside_hours', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $earlySlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 08:00:00';

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => $earlySlot,
            'is_walk_in' => true,
        ])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'outside_hours']);
});

// ---------------------------------------------------------------------------
// Duration resolution (resolveDuration honored via endpoint)
// ---------------------------------------------------------------------------

it('service duration pushes slot into a conflict that the clinic default would not hit', function (): void {
    // Clinic default = 15 min (short — 10:00-10:15, no overlap with 10:30+ appointment).
    // Service duration = 60 min — the slot becomes 10:00-11:00, overlapping the 10:30-11:00 appointment.
    $clinic = Clinic::factory()->create([
        'timezone' => 'Europe/Istanbul',
        'default_slot_duration_minutes' => 15,
    ]);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => 60]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    // Existing appointment at 10:30-11:00 — only conflicting if new slot duration >= 31 min
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $slotStartUtc->copy()->addMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(60),
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Without service_id: clinic default 15 min → 10:00-10:15, no overlap → available
    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'duration_minutes' => null,
            'service_id' => null,
        ])))
        ->assertOk()
        ->assertJson(['available' => true]);

    // With service_id: service 60 min → 10:00-11:00, overlaps 10:30-11:00 → conflict
    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'duration_minutes' => null,
            'service_id' => $service->id,
        ])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'conflict']);
});

it('explicit duration_minutes override wins over service duration', function (): void {
    $clinic = Clinic::factory()->create([
        'timezone' => 'Europe/Istanbul',
        'default_slot_duration_minutes' => 15,
    ]);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => 60]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    // Conflict at 10:30-11:00
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $slotStartUtc->copy()->addMinutes(30),
        'ends_at' => $slotStartUtc->copy()->addMinutes(60),
        'status' => AppointmentStatus::Confirmed,
    ]);

    // duration_minutes=20 explicit override beats the 60-min service → no conflict (10:00-10:20)
    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'duration_minutes' => 20,
            'service_id' => $service->id,
        ])))
        ->assertOk()
        ->assertJson(['available' => true]);
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('probing a clinic-B doctor_id from a clinic-A user returns 422', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $ownerA = User::factory()->create();
    chkRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($ownerA)
        ->getJson(route('appointments.availability', chkParams($doctorB->id)))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');
});

it('clinic-B appointment at same time does not affect clinic-A availability result', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $ownerA = User::factory()->create();
    chkRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    // Clinic B's doctor has a conflicting appointment at the same time
    Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(15),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Clinic A's doctor should still show available (clinic B's data is invisible)
    $this->actingAs($ownerA)
        ->getJson(route('appointments.availability', chkParams($doctorA->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ])))
        ->assertOk()
        ->assertJson(['available' => true, 'reason' => null]);
});

it('clinic-B schedule exception at same time does not affect clinic-A availability result', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $ownerA = User::factory()->create();
    chkRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    // Clinic B's doctor has a schedule exception at the same time
    ScheduleException::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'starts_at' => $slotStartUtc->copy()->subMinutes(15),
        'ends_at' => $slotStartUtc->copy()->addMinutes(15),
    ]);

    // Clinic A's doctor — exception is from clinic B and must not appear
    $this->actingAs($ownerA)
        ->getJson(route('appointments.availability', chkParams($doctorA->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
        ])))
        ->assertOk()
        ->assertJson(['available' => true, 'reason' => null]);
});

// ---------------------------------------------------------------------------
// exclude_appointment_id (reschedule probe — ignore the row's own slot)
// ---------------------------------------------------------------------------

it('exclude_appointment_id removes the appointment\'s own slot from the conflict check', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $mondayDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
    $slotStartUtc = Carbon::parse("{$mondayDate} 10:00:00", 'Europe/Istanbul')->utc();

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $slotStartUtc,
        'ends_at' => $slotStartUtc->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Without exclude → the row conflicts with itself.
    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, ['starts_at' => "{$mondayDate} 10:00:00"])))
        ->assertOk()
        ->assertJson(['available' => false, 'reason' => 'conflict']);

    // With exclude → its own slot is ignored, so the slot reads as available.
    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctor->id, [
            'starts_at' => "{$mondayDate} 10:00:00",
            'exclude_appointment_id' => $appointment->id,
        ])))
        ->assertOk()
        ->assertJson(['available' => true, 'reason' => null]);
});

it('returns 422 when exclude_appointment_id belongs to another clinic', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    chkRole($owner, 'owner', $clinicA->id);
    $doctorUser = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUser->id]);

    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $appointmentB = Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->getJson(route('appointments.availability', chkParams($doctorA->id, [
            'exclude_appointment_id' => $appointmentB->id,
        ])))
        ->assertStatus(422)
        ->assertJsonValidationErrors('exclude_appointment_id');
});
