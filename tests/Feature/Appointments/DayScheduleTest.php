<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
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
function dsRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build the next Monday date string (Y-m-d) in Europe/Istanbul.
 */
function dsNextMonday(): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
}

/**
 * Create an appointment for a given doctor on next Monday that falls within the day window.
 * $startHour and $endHour are clinic-local (Europe/Istanbul) hours.
 *
 * @return array{appointment: Appointment, start: Carbon, end: Carbon}
 */
function dsMakeAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, int $startHour, int $endHour, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
{
    $date = dsNextMonday();
    $tz = $clinic->timezone ?? 'Europe/Istanbul';
    $startsAt = Carbon::parse("{$date} {$startHour}:00:00", $tz)->utc();
    $endsAt = Carbon::parse("{$date} {$endHour}:00:00", $tz)->utc();

    return Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $endsAt,
        'status' => $status,
        'is_walk_in' => false,
    ]);
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('returns 401 for unauthenticated requests to day-schedule endpoint', function (): void {
    $this->getJson(route('appointments.day-schedule', ['doctor_id' => 1, 'date' => dsNextMonday()]))
        ->assertUnauthorized();
});

it('returns 403 for assistant who lacks appointments.create', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    dsRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($assistant)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertForbidden();
});

it('owner can access the day-schedule endpoint', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertOk()
        ->assertJsonStructure(['data']);
});

// ---------------------------------------------------------------------------
// Validation (FormRequest)
// ---------------------------------------------------------------------------

it('returns 422 when doctor_id is missing', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['date' => dsNextMonday()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');
});

it('returns 422 when date is missing', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

it('returns 422 when date is not in Y-m-d format', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => '01/06/2026']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

// ---------------------------------------------------------------------------
// Core behavior
// ---------------------------------------------------------------------------

it('returns empty data array when no appointments exist for the day', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertOk()
        ->assertJson(['data' => []]);
});

it('returns appointments ordered by start time ascending', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Create two appointments out of order
    dsMakeAppointment($clinic, $doctor, $patient, 14, 15);
    dsMakeAppointment($clinic, $doctor, $patient, 10, 11);

    $response = $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(2)
        ->and($data[0]['start_time'])->toBe('10:00')
        ->and($data[1]['start_time'])->toBe('14:00');
});

it('start_time and end_time are returned as HH:mm in the clinic timezone', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    dsMakeAppointment($clinic, $doctor, $patient, 10, 11);

    $response = $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertOk();

    $entry = $response->json('data.0');
    expect($entry['start_time'])->toBe('10:00')
        ->and($entry['end_time'])->toBe('11:00');
});

it('each entry includes id, start_time, end_time, status, is_walk_in, patient_name', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ahmet',
        'last_name' => 'Yılmaz',
    ]);

    dsMakeAppointment($clinic, $doctor, $patient, 10, 11);

    $response = $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertOk();

    $entry = $response->json('data.0');
    expect($entry)->toHaveKeys(['id', 'start_time', 'end_time', 'status', 'is_walk_in', 'patient_name', 'service_name'])
        ->and($entry['patient_name'])->toBe('Ahmet Yılmaz')
        ->and($entry['status'])->toBe('confirmed')
        ->and($entry['is_walk_in'])->toBeFalse()
        ->and($entry['service_name'])->toBeNull();
});

it('returns the service name when the appointment has a service, null otherwise', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Tırnak Bakımı',
    ]);

    dsMakeAppointment($clinic, $doctor, $patient, 9, 10)->update(['service_id' => $service->id]);
    dsMakeAppointment($clinic, $doctor, $patient, 11, 12);

    $data = $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertOk()
        ->json('data');

    expect($data[0]['service_name'])->toBe('Tırnak Bakımı')
        ->and($data[1]['service_name'])->toBeNull();
});

it('excludes Cancelled appointments from the day schedule', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    dsMakeAppointment($clinic, $doctor, $patient, 10, 11, AppointmentStatus::Confirmed);
    dsMakeAppointment($clinic, $doctor, $patient, 14, 15, AppointmentStatus::Cancelled);

    $response = $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(1)
        ->and($data[0]['status'])->toBe('confirmed');
});

it('only returns the requested doctor\'s appointments (not other doctors on the same day)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Doctor A has 1 appointment, Doctor B has 1 appointment at the same time
    dsMakeAppointment($clinic, $doctorA, $patient, 10, 11);
    dsMakeAppointment($clinic, $doctorB, $patient, 10, 11);

    // Querying Doctor A should only return Doctor A's appointment
    $response = $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctorA->id, 'date' => dsNextMonday()]))
        ->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(1);
    foreach ($data as $entry) {
        expect($entry['id'])->not->toBeNull();
    }
});

it('returns appointments for various non-Cancelled statuses', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    dsMakeAppointment($clinic, $doctor, $patient, 9, 10, AppointmentStatus::Confirmed);
    dsMakeAppointment($clinic, $doctor, $patient, 10, 11, AppointmentStatus::Arrived);
    dsMakeAppointment($clinic, $doctor, $patient, 11, 12, AppointmentStatus::Completed);

    $response = $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => dsNextMonday()]))
        ->assertOk();

    $data = $response->json('data');
    $statuses = array_column($data, 'status');
    expect(count($data))->toBe(3)
        ->and($statuses)->toContain('confirmed')
        ->and($statuses)->toContain('arrived')
        ->and($statuses)->toContain('completed');
});

it('walk-in flag is returned correctly in the response', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    dsRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $date = dsNextMonday();
    $tz = 'Europe/Istanbul';
    Appointment::factory()->walkIn()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => Carbon::parse("{$date} 10:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} 11:00:00", $tz)->utc(),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctor->id, 'date' => $date]))
        ->assertOk();

    expect($response->json('data.0.is_walk_in'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('probing a clinic-B doctor_id from a clinic-A user returns 422', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $ownerA = User::factory()->create();
    dsRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($ownerA)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctorB->id, 'date' => dsNextMonday()]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('doctor_id');
});

it('clinic-A user never sees clinic-B appointments in the day schedule', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $ownerA = User::factory()->create();
    dsRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    // Clinic A's doctor has 1 appointment
    dsMakeAppointment($clinicA, $doctorA, $patientA, 10, 11);

    // Clinic B's doctor has 1 appointment at the same time (must NOT appear in clinic-A results)
    dsMakeAppointment($clinicB, $doctorB, $patientB, 10, 11);

    $response = $this->actingAs($ownerA)
        ->getJson(route('appointments.day-schedule', ['doctor_id' => $doctorA->id, 'date' => dsNextMonday()]))
        ->assertOk();

    $data = $response->json('data');
    expect(count($data))->toBe(1)
        ->and($data[0]['patient_name'])->toBe(
            trim($patientA->first_name.' '.$patientA->last_name)
        );
});
