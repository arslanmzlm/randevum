<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ScheduleException;
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
function calRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Next Monday in Europe/Istanbul as Y-m-d.
 */
function calNextMonday(): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d');
}

/**
 * Create an appointment within the test date window using clinic-local hours.
 */
function calMakeAppointment(
    Clinic $clinic,
    Doctor $doctor,
    Patient $patient,
    int $startHour,
    int $endHour,
    AppointmentStatus $status = AppointmentStatus::Confirmed,
): Appointment {
    $date = calNextMonday();
    $tz = $clinic->timezone ?? 'Europe/Istanbul';

    return Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => Carbon::parse("{$date} {$startHour}:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} {$endHour}:00:00", $tz)->utc(),
        'status' => $status,
        'is_walk_in' => false,
    ]);
}

/**
 * Build the full URL for calendar.events with query params.
 * Using http_build_query ensures array params (statuses[]) are encoded correctly.
 *
 * @param  array<string, mixed>  $extra
 */
function calEventsUrl(array $extra = []): string
{
    $date = calNextMonday();
    $params = array_merge(['start' => $date, 'end' => $date], $extra);

    return route('calendar.events').'?'.http_build_query($params);
}

// ---------------------------------------------------------------------------
// Authorization — calendar.index
// ---------------------------------------------------------------------------

it('redirects unauthenticated users from GET /calendar to login', function (): void {
    $this->get(route('calendar.index'))
        ->assertRedirect(route('login'));
});

it('returns 401 for unauthenticated requests to calendar.events', function (): void {
    $this->getJson(calEventsUrl())
        ->assertUnauthorized();
});

it('owner can access calendar.index', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('calendar/Index'));
});

it('manager can access calendar.index', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $manager = User::factory()->create();
    calRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('calendar.index'))
        ->assertOk();
});

it('doctor role user can access calendar.index', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $doctorUser = User::factory()->create();
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    calRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('calendar.index'))
        ->assertOk();
});

it('receptionist can access calendar.index', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $receptionist = User::factory()->create();
    calRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('calendar.index'))
        ->assertOk();
});

it('assistant can access calendar.index', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $assistant = User::factory()->create();
    calRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('calendar.index'))
        ->assertOk();
});

it('user without a clinic role gets 403 from calendar.index', function (): void {
    $plainUser = User::factory()->create();

    $this->actingAs($plainUser)
        ->get(route('calendar.index'))
        ->assertForbidden();
});

it('user without a clinic role gets 403 from calendar.events', function (): void {
    $plainUser = User::factory()->create();

    $this->actingAs($plainUser)
        ->getJson(calEventsUrl())
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Inertia props contract — calendar.index
// ---------------------------------------------------------------------------

it('calendar.index renders the calendar/Index component with the expected prop shape', function (): void {
    $clinic = Clinic::factory()->create([
        'timezone' => 'Europe/Istanbul',
        'default_slot_duration_minutes' => 30,
    ]);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page
            ->component('calendar/Index')
            ->has('doctors')
            ->has('ownDoctorId')
            ->has('workingHours')
            ->where('timezone', 'Europe/Istanbul')
            ->where('defaultSlotDuration', 30)
            ->where('defaultView', 'week')
        );
});

it('ownDoctorId matches the authenticated user\'s doctor profile id', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    calRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page->where('ownDoctorId', $doctor->id));
});

it('ownDoctorId is null when the authenticated user has no doctor profile', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('calendar.index'))
        ->assertInertia(fn ($page) => $page->where('ownDoctorId', null));
});

// ---------------------------------------------------------------------------
// Validation — CalendarEventsRequest
// ---------------------------------------------------------------------------

it('returns 422 when start is missing', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(route('calendar.events').'?end='.calNextMonday())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start');
});

it('returns 422 when end is missing', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(route('calendar.events').'?start='.calNextMonday())
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end');
});

it('returns 422 when start is not a valid Y-m-d date string', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $date = calNextMonday();

    $this->actingAs($owner)
        ->getJson(route('calendar.events').'?'.http_build_query(['start' => '01/06/2026', 'end' => $date]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('start');
});

it('returns 422 when end is before start', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $date = calNextMonday();
    $yesterday = Carbon::parse($date)->subDay()->format('Y-m-d');

    $this->actingAs($owner)
        ->getJson(route('calendar.events').'?'.http_build_query(['start' => $date, 'end' => $yesterday]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end');
});

it('returns 422 when the range exceeds 45 days', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $start = calNextMonday();
    $end = Carbon::parse($start)->addDays(46)->format('Y-m-d');

    $this->actingAs($owner)
        ->getJson(route('calendar.events').'?'.http_build_query(['start' => $start, 'end' => $end]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end');
});

it('accepts a 45-day range without error', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $start = calNextMonday();
    $end = Carbon::parse($start)->addDays(45)->format('Y-m-d');

    $this->actingAs($owner)
        ->getJson(route('calendar.events').'?'.http_build_query(['start' => $start, 'end' => $end]))
        ->assertOk();
});

it('returns 422 when statuses contains a Faz-2 status (pending)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(calEventsUrl(['statuses' => ['pending']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('statuses.0');
});

it('returns 422 when statuses contains a Faz-2 status (no_show)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(calEventsUrl(['statuses' => ['no_show']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('statuses.0');
});

it('returns 422 when statuses contains an unrecognised value', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(calEventsUrl(['statuses' => ['bogus_status']]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('statuses.0');
});

// ---------------------------------------------------------------------------
// Status filter — default and explicit selection
// ---------------------------------------------------------------------------

it('excludes cancelled by default (no statuses param)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    calMakeAppointment($clinic, $doctor, $patient, 10, 11, AppointmentStatus::Confirmed);
    calMakeAppointment($clinic, $doctor, $patient, 14, 15, AppointmentStatus::Cancelled);

    $data = $this->actingAs($owner)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data');

    expect(count($data))->toBe(1)
        ->and($data[0]['status'])->toBe('confirmed');
});

it('all non-cancelled MVP statuses are visible by default', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    calMakeAppointment($clinic, $doctor, $patient, 9, 10, AppointmentStatus::Confirmed);
    calMakeAppointment($clinic, $doctor, $patient, 10, 11, AppointmentStatus::Rescheduled);
    calMakeAppointment($clinic, $doctor, $patient, 11, 12, AppointmentStatus::Arrived);
    calMakeAppointment($clinic, $doctor, $patient, 12, 13, AppointmentStatus::Completed);
    calMakeAppointment($clinic, $doctor, $patient, 14, 15, AppointmentStatus::Cancelled);

    $data = $this->actingAs($owner)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data');

    $statuses = array_column($data, 'status');
    expect(count($data))->toBe(4)
        ->and($statuses)->toContain('confirmed')
        ->and($statuses)->toContain('rescheduled')
        ->and($statuses)->toContain('arrived')
        ->and($statuses)->toContain('completed')
        ->and($statuses)->not->toContain('cancelled');
});

it('cancelled appointments appear when statuses includes cancelled', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    calMakeAppointment($clinic, $doctor, $patient, 10, 11, AppointmentStatus::Confirmed);
    calMakeAppointment($clinic, $doctor, $patient, 14, 15, AppointmentStatus::Cancelled);

    $data = $this->actingAs($owner)
        ->getJson(calEventsUrl(['statuses' => ['confirmed', 'cancelled']]))
        ->assertOk()
        ->json('data');

    $statuses = array_column($data, 'status');
    expect(count($data))->toBe(2)
        ->and($statuses)->toContain('confirmed')
        ->and($statuses)->toContain('cancelled');
});

it('passing statuses[]=cancelled only returns only the cancelled appointment', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    calMakeAppointment($clinic, $doctor, $patient, 10, 11, AppointmentStatus::Confirmed);
    calMakeAppointment($clinic, $doctor, $patient, 14, 15, AppointmentStatus::Cancelled);

    $data = $this->actingAs($owner)
        ->getJson(calEventsUrl(['statuses' => ['cancelled']]))
        ->assertOk()
        ->json('data');

    expect(count($data))->toBe(1)
        ->and($data[0]['status'])->toBe('cancelled');
});

// ---------------------------------------------------------------------------
// Doctor scope — own vs all
// ---------------------------------------------------------------------------

it('doctor role user (no viewAll) sees only their own appointments', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    calRole($doctorUserA, 'doctor', $clinic->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    calMakeAppointment($clinic, $doctorA, $patient, 10, 11);
    calMakeAppointment($clinic, $doctorB, $patient, 12, 13);

    $data = $this->actingAs($doctorUserA)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data');

    expect(count($data))->toBe(1)
        ->and($data[0]['doctor_id'])->toBe($doctorA->id);
});

it('doctor role user without a doctor profile gets empty data and exceptions', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $doctorUser = User::factory()->create();
    // Doctor role assigned but no Doctor profile row created
    calRole($doctorUser, 'doctor', $clinic->id);

    $response = $this->actingAs($doctorUser)
        ->getJson(calEventsUrl())
        ->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('exceptions'))->toBe([]);
});

it('owner (viewAll) sees all clinic doctors appointments', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    calMakeAppointment($clinic, $doctorA, $patient, 10, 11);
    calMakeAppointment($clinic, $doctorB, $patient, 12, 13);

    $data = $this->actingAs($owner)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data');

    expect(count($data))->toBe(2);
    $doctorIds = array_column($data, 'doctor_id');
    expect($doctorIds)->toContain($doctorA->id)
        ->and($doctorIds)->toContain($doctorB->id);
});

it('doctor_id filter narrows results for a viewAll user to the specified doctor only', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    calMakeAppointment($clinic, $doctorA, $patient, 10, 11);
    calMakeAppointment($clinic, $doctorB, $patient, 12, 13);

    $data = $this->actingAs($owner)
        ->getJson(calEventsUrl(['doctor_id' => $doctorA->id]))
        ->assertOk()
        ->json('data');

    expect(count($data))->toBe(1)
        ->and($data[0]['doctor_id'])->toBe($doctorA->id);
});

// ---------------------------------------------------------------------------
// DTO format and field completeness
// ---------------------------------------------------------------------------

it('event start and end are returned as clinic-local Y-m-d H:i strings', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $date = calNextMonday();
    calMakeAppointment($clinic, $doctor, $patient, 10, 11);

    $event = $this->actingAs($owner)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data.0');

    // Stored as UTC, returned as Europe/Istanbul wall-clock strings
    expect($event['start'])->toBe("{$date} 10:00")
        ->and($event['end'])->toBe("{$date} 11:00");
});

it('event DTO contains all required fields', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ali',
        'last_name' => 'Veli',
    ]);

    calMakeAppointment($clinic, $doctor, $patient, 10, 11);

    $event = $this->actingAs($owner)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data.0');

    expect($event)->toHaveKeys([
        'id', 'doctor_id', 'doctor_name', 'title',
        'start', 'end', 'status', 'is_walk_in',
        'service_name', 'type_name', 'type_color',
    ])
        ->and($event['title'])->toBe('Ali Veli')
        ->and($event['status'])->toBe('confirmed')
        ->and((bool) $event['is_walk_in'])->toBeFalse()
        ->and($event['service_name'])->toBeNull()
        ->and($event['type_name'])->toBeNull()
        ->and($event['type_color'])->toBeNull();
});

it('is_walk_in is true for walk-in appointments', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $date = calNextMonday();
    $tz = 'Europe/Istanbul';

    Appointment::factory()->walkIn()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => Carbon::parse("{$date} 10:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} 11:00:00", $tz)->utc(),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $event = $this->actingAs($owner)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data.0');

    expect((bool) $event['is_walk_in'])->toBeTrue();
});

// ---------------------------------------------------------------------------
// Schedule exceptions in the response
// ---------------------------------------------------------------------------

it('exceptions in the response include schedule_exceptions for the resolved doctor scope', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $date = calNextMonday();
    $tz = 'Europe/Istanbul';

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => Carbon::parse("{$date} 13:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} 14:00:00", $tz)->utc(),
        'reason' => 'Öğle Molası',
    ]);

    $exceptions = $this->actingAs($owner)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('exceptions');

    expect(count($exceptions))->toBeGreaterThanOrEqual(1);

    $found = collect($exceptions)->firstWhere('doctor_id', $doctor->id);
    expect($found)->not->toBeNull()
        ->and($found['start'])->toBe("{$date} 13:00")
        ->and($found['end'])->toBe("{$date} 14:00");
});

it('exception DTO has id, doctor_id, start, end, and reason fields', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    calRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $date = calNextMonday();
    $tz = 'Europe/Istanbul';

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => Carbon::parse("{$date} 13:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} 14:00:00", $tz)->utc(),
        'reason' => 'İzin',
    ]);

    $exception = $this->actingAs($owner)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('exceptions.0');

    expect($exception)->toHaveKeys(['id', 'doctor_id', 'start', 'end', 'reason'])
        ->and($exception['reason'])->toBe('İzin');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic B appointments never appear in clinic A calendar.events response', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerA = User::factory()->create();
    calRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    calMakeAppointment($clinicA, $doctorA, $patientA, 10, 11);
    calMakeAppointment($clinicB, $doctorB, $patientB, 10, 11);

    $data = $this->actingAs($ownerA)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data');

    // Clinic A's owner sees exactly 1 appointment — only their clinic's
    expect(count($data))->toBe(1)
        ->and($data[0]['doctor_id'])->toBe($doctorA->id);
});

it('clinic B exceptions never appear in clinic A calendar.events response', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerA = User::factory()->create();
    calRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $date = calNextMonday();
    $tz = 'Europe/Istanbul';

    ScheduleException::factory()->create([
        'clinic_id' => $clinicA->id,
        'doctor_id' => $doctorA->id,
        'starts_at' => Carbon::parse("{$date} 13:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} 14:00:00", $tz)->utc(),
    ]);

    ScheduleException::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'starts_at' => Carbon::parse("{$date} 13:00:00", $tz)->utc(),
        'ends_at' => Carbon::parse("{$date} 14:00:00", $tz)->utc(),
    ]);

    $exceptions = $this->actingAs($ownerA)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('exceptions');

    // All returned exceptions must belong to clinic A's doctor
    foreach ($exceptions as $exception) {
        expect($exception['doctor_id'])->toBe($doctorA->id);
    }
});

it('clinic B owner cannot see clinic A appointments (symmetric isolation)', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $ownerA = User::factory()->create();
    calRole($ownerA, 'owner', $clinicA->id);
    $ownerB = User::factory()->create();
    calRole($ownerB, 'owner', $clinicB->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    calMakeAppointment($clinicA, $doctorA, $patientA, 10, 11);
    calMakeAppointment($clinicB, $doctorB, $patientB, 12, 13);

    // Clinic B's owner sees only their own appointment
    $dataB = $this->actingAs($ownerB)
        ->getJson(calEventsUrl())
        ->assertOk()
        ->json('data');

    expect(count($dataB))->toBe(1)
        ->and($dataB[0]['doctor_id'])->toBe($doctorB->id);
});
