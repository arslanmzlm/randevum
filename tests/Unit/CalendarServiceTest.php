<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleException;
use App\Models\User;
use App\Modules\Scheduling\Services\CalendarService;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function csRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Simulate the SetClinicContext middleware for a clinic (sets PermissionRegistrar team + ClinicContext).
 */
function csActivateClinic(Clinic $clinic): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    app(ClinicContext::class)->set($clinic->id);
}

/**
 * Next Monday in Europe/Istanbul as Y-m-d.
 */
function csNextMonday(): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
}

/**
 * Create a UTC Carbon at the given clinic-local hour on next Monday.
 */
function csUtc(Clinic $clinic, int $hour): Carbon
{
    $date = csNextMonday();
    $tz = $clinic->timezone ?? 'Europe/Istanbul';

    return Carbon::parse("{$date} {$hour}:00:00", $tz)->utc();
}

/**
 * Create an appointment for the given clinic + doctor + patient at clinic-local hours.
 */
function csMakeAppointment(
    Clinic $clinic,
    Doctor $doctor,
    Patient $patient,
    int $startHour,
    int $endHour,
    AppointmentStatus $status = AppointmentStatus::Confirmed,
): Appointment {
    return Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => csUtc($clinic, $startHour),
        'ends_at' => csUtc($clinic, $endHour),
        'status' => $status,
        'is_walk_in' => false,
    ]);
}

/**
 * Return start/end UTC Carbon instances spanning next Monday for the given clinic timezone.
 *
 * @return array{Carbon, Carbon}
 */
function csRangeUtc(Clinic $clinic): array
{
    $date = csNextMonday();
    $tz = $clinic->timezone ?? 'Europe/Istanbul';

    return [
        Carbon::parse("{$date} 00:00:00", $tz)->utc(),
        Carbon::parse("{$date} 23:59:59", $tz)->utc(),
    ];
}

// ---------------------------------------------------------------------------
// viewAll scope resolution
// ---------------------------------------------------------------------------

test('eventsFor with viewAll user returns all clinic doctors appointments', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerUser = User::factory()->create();
    csRole($ownerUser, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    csMakeAppointment($clinic, $doctorA, $patient, 10, 11);
    csMakeAppointment($clinic, $doctorB, $patient, 12, 13);

    csActivateClinic($clinic);
    [$startUtc, $endUtc] = csRangeUtc($clinic);

    $result = app(CalendarService::class)->eventsFor(
        $ownerUser,
        $startUtc,
        $endUtc,
        null,
        [AppointmentStatus::Confirmed],
        $clinic,
    );

    expect(count($result['data']))->toBe(2);
    $doctorIds = array_column($result['data'], 'doctor_id');
    expect($doctorIds)->toContain($doctorA->id)
        ->and($doctorIds)->toContain($doctorB->id);
});

test('eventsFor with viewAll user and a doctorId returns only that doctors appointments', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerUser = User::factory()->create();
    csRole($ownerUser, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    csMakeAppointment($clinic, $doctorA, $patient, 10, 11);
    csMakeAppointment($clinic, $doctorB, $patient, 12, 13);

    csActivateClinic($clinic);
    [$startUtc, $endUtc] = csRangeUtc($clinic);

    $result = app(CalendarService::class)->eventsFor(
        $ownerUser,
        $startUtc,
        $endUtc,
        $doctorA->id,
        [AppointmentStatus::Confirmed],
        $clinic,
    );

    expect(count($result['data']))->toBe(1)
        ->and($result['data'][0]['doctor_id'])->toBe($doctorA->id);
});

// ---------------------------------------------------------------------------
// own-doctor scope resolution (no viewAll)
// ---------------------------------------------------------------------------

test('eventsFor without viewAll returns only the users own doctor appointments', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    csRole($doctorUserA, 'doctor', $clinic->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    csMakeAppointment($clinic, $doctorA, $patient, 10, 11);
    csMakeAppointment($clinic, $doctorB, $patient, 12, 13);

    csActivateClinic($clinic);
    [$startUtc, $endUtc] = csRangeUtc($clinic);

    $result = app(CalendarService::class)->eventsFor(
        $doctorUserA,
        $startUtc,
        $endUtc,
        null,
        [AppointmentStatus::Confirmed],
        $clinic,
    );

    expect(count($result['data']))->toBe(1)
        ->and($result['data'][0]['doctor_id'])->toBe($doctorA->id);
});

test('eventsFor without viewAll and no doctor profile returns empty data and exceptions', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $doctorUser = User::factory()->create();
    // Doctor role assigned but no Doctor profile row
    csRole($doctorUser, 'doctor', $clinic->id);

    csActivateClinic($clinic);
    [$startUtc, $endUtc] = csRangeUtc($clinic);

    $result = app(CalendarService::class)->eventsFor(
        $doctorUser,
        $startUtc,
        $endUtc,
        null,
        [AppointmentStatus::Confirmed],
        $clinic,
    );

    expect($result['data'])->toBe([])
        ->and($result['exceptions'])->toBe([]);
});

// ---------------------------------------------------------------------------
// DTO time formatting — clinic-local wall-clock strings
// ---------------------------------------------------------------------------

test('eventsFor formats appointment start and end as clinic-local Y-m-d H:i strings', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerUser = User::factory()->create();
    csRole($ownerUser, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $date = csNextMonday();
    csMakeAppointment($clinic, $doctor, $patient, 10, 11);

    csActivateClinic($clinic);
    [$startUtc, $endUtc] = csRangeUtc($clinic);

    $result = app(CalendarService::class)->eventsFor(
        $ownerUser,
        $startUtc,
        $endUtc,
        null,
        [AppointmentStatus::Confirmed],
        $clinic,
    );

    expect(count($result['data']))->toBe(1)
        ->and($result['data'][0]['start'])->toBe("{$date} 10:00")
        ->and($result['data'][0]['end'])->toBe("{$date} 11:00");
});

test('eventsFor formats exception start and end as clinic-local Y-m-d H:i strings', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerUser = User::factory()->create();
    csRole($ownerUser, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $date = csNextMonday();
    $tz = 'Europe/Istanbul';

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => Carbon::parse("{$date} 13:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} 14:00:00", $tz)->utc(),
        'reason' => 'Break',
    ]);

    csActivateClinic($clinic);
    [$startUtc, $endUtc] = csRangeUtc($clinic);

    $result = app(CalendarService::class)->eventsFor(
        $ownerUser,
        $startUtc,
        $endUtc,
        null,
        [AppointmentStatus::Confirmed],
        $clinic,
    );

    expect(count($result['exceptions']))->toBe(1)
        ->and($result['exceptions'][0]['start'])->toBe("{$date} 13:00")
        ->and($result['exceptions'][0]['end'])->toBe("{$date} 14:00")
        ->and($result['exceptions'][0]['reason'])->toBe('Break');
});

// ---------------------------------------------------------------------------
// Status filter
// ---------------------------------------------------------------------------

test('eventsFor respects the statuses filter and excludes unmatched statuses', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerUser = User::factory()->create();
    csRole($ownerUser, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    csMakeAppointment($clinic, $doctor, $patient, 10, 11, AppointmentStatus::Confirmed);
    csMakeAppointment($clinic, $doctor, $patient, 12, 13, AppointmentStatus::Cancelled);

    csActivateClinic($clinic);
    [$startUtc, $endUtc] = csRangeUtc($clinic);

    // Only request Confirmed — Cancelled should be absent
    $result = app(CalendarService::class)->eventsFor(
        $ownerUser,
        $startUtc,
        $endUtc,
        null,
        [AppointmentStatus::Confirmed],
        $clinic,
    );

    expect(count($result['data']))->toBe(1)
        ->and($result['data'][0]['status'])->toBe('confirmed');
});

test('eventsFor includes cancelled when Cancelled is in the statuses list', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerUser = User::factory()->create();
    csRole($ownerUser, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    csMakeAppointment($clinic, $doctor, $patient, 10, 11, AppointmentStatus::Confirmed);
    csMakeAppointment($clinic, $doctor, $patient, 12, 13, AppointmentStatus::Cancelled);

    csActivateClinic($clinic);
    [$startUtc, $endUtc] = csRangeUtc($clinic);

    $result = app(CalendarService::class)->eventsFor(
        $ownerUser,
        $startUtc,
        $endUtc,
        null,
        [AppointmentStatus::Confirmed, AppointmentStatus::Cancelled],
        $clinic,
    );

    $statuses = array_column($result['data'], 'status');
    expect(count($result['data']))->toBe(2)
        ->and($statuses)->toContain('confirmed')
        ->and($statuses)->toContain('cancelled');
});
