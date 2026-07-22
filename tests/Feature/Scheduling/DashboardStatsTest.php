<?php

use App\Enums\AppointmentStatus;
use App\Enums\TransactionStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Transaction;
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
function dashRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic + owner + doctor user + patient.
 * The clinic uses Europe/Istanbul (UTC+3) like the factory default.
 *
 * @return array{clinic: Clinic, owner: User, doctorUser: User, doctor: Doctor, patient: Patient}
 */
function dsSetup(): array
{
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']);
    $owner = User::factory()->create();
    dashRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'doctorUser', 'doctor', 'patient');
}

/**
 * Returns a Carbon UTC timestamp corresponding to the given clinic-local date and time.
 */
function dsUtcFor(string $clinicLocalDatetime, string $timezone = 'Europe/Istanbul'): Carbon
{
    return Carbon::parse($clinicLocalDatetime, $timezone)->utc();
}

/**
 * Clinic-local "today" start in UTC.
 */
function dsTodayStartUtc(string $timezone = 'Europe/Istanbul'): Carbon
{
    return Carbon::now($timezone)->startOfDay()->utc();
}

/**
 * Clinic-local "today" end in UTC.
 */
function dsTodayEndUtc(string $timezone = 'Europe/Istanbul'): Carbon
{
    return Carbon::now($timezone)->endOfDay()->utc();
}

// ---------------------------------------------------------------------------
// Basic rendering — stats prop is present
// ---------------------------------------------------------------------------

it('GET /dashboard renders for an authed clinic owner with a stats prop', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('stats'));
});

it('stats prop contains appointments and revenue keys', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('stats.appointments')
            ->has('stats.revenue')
        );
});

// ---------------------------------------------------------------------------
// Permission gating — appointments block
// ---------------------------------------------------------------------------

it('stats.appointments is non-null for owner (has appointments.viewAny)', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->whereNot('stats.appointments', null)
        );
});

it('stats.appointments is null for a user with no clinic role (lacks appointments.viewAny)', function (): void {
    // A global 'patient' role user assigned to no clinic has no appointments.viewAny
    ['clinic' => $clinic] = dsSetup();

    // User with no clinic-scoped role: the ClinicContext middleware sets clinic from
    // the logged-in user's membership, but a role-less user has no membership and
    // the DashboardStatsService returns null blocks.
    $noRole = User::factory()->create();
    // Assign the global 'patient' role (no clinic scope → no clinic permissions)
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $noRole->assignRole('patient');
    $noRole->unsetRelation('roles');
    $noRole->unsetRelation('permissions');

    // Without a clinic context the service short-circuits to [appointments=>null, revenue=>null]
    $this->actingAs($noRole)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments', null)
        );
});

// ---------------------------------------------------------------------------
// Permission gating — revenue block
// ---------------------------------------------------------------------------

it('stats.revenue is non-null for owner (has transactions.viewAny)', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->whereNot('stats.revenue', null)
        );
});

it('stats.revenue is null for assistant (lacks transactions.viewAny)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = dsSetup();

    // Assistant has appointments.viewAny but NOT transactions.viewAny — perfect for this gate test
    $assistant = User::factory()->create();
    dashRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.revenue', null)
        );
});

it('revenue figure never leaks to an assistant (lacks transactions.viewAny)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = dsSetup();

    // Seed a transaction that should NOT be visible to assistant
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '500.00',
        'paid_at' => now(),
    ]);

    $assistant = User::factory()->create();
    dashRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.revenue', null));
});

// ---------------------------------------------------------------------------
// Appointments tile — count correctness (today)
// ---------------------------------------------------------------------------

it('today count includes a confirmed appointment starting today in clinic tz', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $todayAt10 = dsUtcFor(Carbon::now('Europe/Istanbul')->format('Y-m-d').' 10:00:00');

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayAt10,
        'ends_at' => $todayAt10->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.today', 1)
        );
});

it('today count excludes a cancelled appointment', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $todayAt10 = dsUtcFor(Carbon::now('Europe/Istanbul')->format('Y-m-d').' 10:00:00');

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayAt10,
        'ends_at' => $todayAt10->copy()->addMinutes(30),
        'status' => AppointmentStatus::Cancelled,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.today', 0)
        );
});

it('today count excludes a no-show appointment', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $todayAt10 = dsUtcFor(Carbon::now('Europe/Istanbul')->format('Y-m-d').' 10:00:00');

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayAt10,
        'ends_at' => $todayAt10->copy()->addMinutes(30),
        'status' => AppointmentStatus::NoShow,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.today', 0)
        );
});

it('today count excludes an appointment that starts yesterday (clinic tz boundary)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    // Yesterday 23:00 Istanbul = still yesterday in clinic tz
    $yesterdayAt23 = dsUtcFor(Carbon::now('Europe/Istanbul')->subDay()->format('Y-m-d').' 23:00:00');

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $yesterdayAt23,
        'ends_at' => $yesterdayAt23->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.today', 0)
        );
});

// ---------------------------------------------------------------------------
// Appointments tile — pending count
// ---------------------------------------------------------------------------

it('pending count includes future pending appointments', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
        'status' => AppointmentStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.pending', 1)
        );
});

it('pending count excludes a past pending appointment', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDays(2)->addMinutes(30),
        'status' => AppointmentStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.pending', 0)
        );
});

// ---------------------------------------------------------------------------
// Appointments tile — this_week count
// ---------------------------------------------------------------------------

it('this_week count includes a confirmed appointment in the current ISO week (clinic tz)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $thisWeekStart = Carbon::now('Europe/Istanbul')->startOfWeek()->addHours(9)->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $thisWeekStart,
        'ends_at' => $thisWeekStart->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.this_week', fn ($v) => $v >= 1)
        );
});

it('this_week count excludes a cancelled appointment this week', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $thisWeekStart = Carbon::now('Europe/Istanbul')->startOfWeek()->addHours(9)->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $thisWeekStart,
        'ends_at' => $thisWeekStart->copy()->addMinutes(30),
        'status' => AppointmentStatus::Cancelled,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.this_week', 0)
        );
});

it('this_week count excludes an appointment from last week', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $lastWeek = Carbon::now('Europe/Istanbul')->subWeek()->startOfWeek()->addHours(9)->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $lastWeek,
        'ends_at' => $lastWeek->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.this_week', 0)
        );
});

// ---------------------------------------------------------------------------
// Appointments tile — no_show_rate (this month)
// ---------------------------------------------------------------------------

it('no_show_rate is null when there are no resolved appointments this month', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.no_show_rate', null)
        );
});

it('no_show_rate computes percent/counts from this-month NoShow/Completed/Arrived appointments', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $thisMonth = Carbon::now('Europe/Istanbul')->startOfMonth()->addDays(2)->addHours(10)->utc();

    // 1 NoShow + 3 Completed this month → 25.0% (1/4).
    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => $thisMonth, 'ends_at' => $thisMonth->copy()->addMinutes(30),
        'status' => AppointmentStatus::NoShow,
    ]);
    Appointment::factory()->count(3)->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => $thisMonth, 'ends_at' => $thisMonth->copy()->addMinutes(30),
        'status' => AppointmentStatus::Completed,
    ]);

    // Confirmed/Rescheduled/Cancelled must NOT be counted in either numerator or denominator.
    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => $thisMonth, 'ends_at' => $thisMonth->copy()->addMinutes(30),
        'status' => AppointmentStatus::Cancelled,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.no_show_rate.percent', 25)
            ->where('stats.appointments.no_show_rate.no_show', 1)
            ->where('stats.appointments.no_show_rate.expected', 4)
        );
});

it('no_show_rate excludes an Arrived-counted appointment from last month', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $lastMonth = Carbon::now('Europe/Istanbul')->subMonth()->startOfMonth()->addHours(10)->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => $lastMonth, 'ends_at' => $lastMonth->copy()->addMinutes(30),
        'status' => AppointmentStatus::NoShow,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.no_show_rate', null)
        );
});

it('no_show_rate is doctor-scoped for a doctor without appointments.viewAll', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();
    dashRole($doctorUser, 'doctor', $clinic->id);

    $thisMonth = Carbon::now('Europe/Istanbul')->startOfMonth()->addDays(2)->addHours(10)->utc();

    // Acting doctor's own NoShow — counted.
    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => $thisMonth, 'ends_at' => $thisMonth->copy()->addMinutes(30),
        'status' => AppointmentStatus::NoShow,
    ]);

    // Another doctor's Completed appointments — must NOT count toward this doctor's rate.
    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    Appointment::factory()->count(9)->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $otherDoctor->id, 'patient_id' => $patient->id,
        'starts_at' => $thisMonth, 'ends_at' => $thisMonth->copy()->addMinutes(30),
        'status' => AppointmentStatus::Completed,
    ]);

    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.no_show_rate.percent', 100)
            ->where('stats.appointments.no_show_rate.no_show', 1)
            ->where('stats.appointments.no_show_rate.expected', 1)
        );
});

it('no_show_rate does not leak another clinic\'s resolved appointments (multi-tenant isolation)', function (): void {
    ['clinic' => $clinicA, 'owner' => $ownerA, 'doctor' => $doctorA, 'patient' => $patientA] = dsSetup();

    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $thisMonth = Carbon::now('Europe/Istanbul')->startOfMonth()->addDays(2)->addHours(10)->utc();

    Appointment::factory()->create([
        'clinic_id' => $clinicA->id, 'doctor_id' => $doctorA->id, 'patient_id' => $patientA->id,
        'starts_at' => $thisMonth, 'ends_at' => $thisMonth->copy()->addMinutes(30),
        'status' => AppointmentStatus::NoShow,
    ]);

    // Clinic B: a large, unrelated NoShow batch that must never bleed into A's rate.
    Appointment::factory()->count(5)->create([
        'clinic_id' => $clinicB->id, 'doctor_id' => $doctorB->id, 'patient_id' => $patientB->id,
        'starts_at' => $thisMonth, 'ends_at' => $thisMonth->copy()->addMinutes(30),
        'status' => AppointmentStatus::NoShow,
    ]);

    $this->actingAs($ownerA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.no_show_rate.percent', 100)
            ->where('stats.appointments.no_show_rate.no_show', 1)
            ->where('stats.appointments.no_show_rate.expected', 1)
        );
});

// ---------------------------------------------------------------------------
// Doctor-scoping — own-only vs clinic-wide
// ---------------------------------------------------------------------------

it('doctor without appointments.viewAll sees only their own appointments in today count', function (): void {
    ['clinic' => $clinic, 'doctorUser' => $doctorUser, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();
    dashRole($doctorUser, 'doctor', $clinic->id);

    $todayAt10 = dsUtcFor(Carbon::now('Europe/Istanbul')->format('Y-m-d').' 10:00:00');

    // Doctor's own appointment
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayAt10,
        'ends_at' => $todayAt10->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Another doctor's appointment — must NOT count for this doctor
    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $otherDoctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayAt10->copy()->addHours(1),
        'ends_at' => $todayAt10->copy()->addHours(1)->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.today', 1)
        );
});

it('owner with appointments.viewAll sees all clinic appointments in today count', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $todayAt10 = dsUtcFor(Carbon::now('Europe/Istanbul')->format('Y-m-d').' 10:00:00');

    // Two doctors, two appointments
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayAt10,
        'ends_at' => $todayAt10->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $otherDoctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayAt10->copy()->addHours(1),
        'ends_at' => $todayAt10->copy()->addHours(1)->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.today', 2)
        );
});

it('a user with appointments.viewAny but no doctor profile sees today count = 0', function (): void {
    ['clinic' => $clinic] = dsSetup();

    // Create a user with the doctor role but no Doctor profile row
    $doctorUser = User::factory()->create();
    dashRole($doctorUser, 'doctor', $clinic->id);
    // Deliberately NO Doctor::factory() for this user

    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.today', 0)
            ->where('stats.appointments.pending', 0)
            ->where('stats.appointments.this_week', 0)
        );
});

// ---------------------------------------------------------------------------
// Revenue tile
// ---------------------------------------------------------------------------

it('today_collected sums positive transaction amounts for the active clinic', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = dsSetup();

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '300.00',
        'paid_at' => now(),
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '200.50',
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.revenue.today_collected', '500.50')
        );
});

it('today_collected is net of refund counter-entries', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = dsSetup();

    // Original payment
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '500.00',
        'paid_at' => now(),
    ]);

    // Refund counter-entry (negative amount)
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '-100.00',
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.revenue.today_collected', '400.00')
        );
});

it('today_collected excludes pending transactions (uncollected installments)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = dsSetup();

    // A Completed transaction that should count
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '400.00',
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
    ]);

    // A Pending transaction with paid_at today — must NOT be summed into collected revenue
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '200.00',
        'status' => TransactionStatus::Pending,
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.revenue.today_collected', '400.00')
        );
});

it('today_collected excludes transactions from yesterday (clinic tz boundary)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = dsSetup();

    // Yesterday in clinic tz
    $yesterdayEnd = Carbon::now('Europe/Istanbul')->subDay()->endOfDay()->utc();

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '999.00',
        'paid_at' => $yesterdayEnd,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.revenue.today_collected', '0.00')
        );
});

it('stats.revenue carries the currency from the clinic', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.revenue.currency', 'TRY')
        );
});

it('today_collected returns 0.00 when there are no transactions today', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.revenue.today_collected', '0.00')
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A appointment counts do not include clinic B appointments', function (): void {
    // Clinic A
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']);
    $ownerA = User::factory()->create();
    dashRole($ownerA, 'owner', $clinicA->id);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    // Clinic B
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $todayAt10 = dsUtcFor(Carbon::now('Europe/Istanbul')->format('Y-m-d').' 10:00:00');

    // Clinic A: 1 appointment
    Appointment::factory()->create([
        'clinic_id' => $clinicA->id,
        'doctor_id' => $doctorA->id,
        'patient_id' => $patientA->id,
        'starts_at' => $todayAt10,
        'ends_at' => $todayAt10->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Clinic B: 3 appointments — must NOT appear in clinic A's count
    Appointment::factory()->count(3)->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
        'starts_at' => $todayAt10->copy()->addHours(1),
        'ends_at' => $todayAt10->copy()->addHours(1)->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($ownerA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.appointments.today', 1)
        );
});

it('clinic A revenue does not include clinic B transactions', function (): void {
    // Clinic A
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']);
    $ownerA = User::factory()->create();
    dashRole($ownerA, 'owner', $clinicA->id);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    // Clinic B
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    // Clinic A: 200.00
    Transaction::factory()->create([
        'clinic_id' => $clinicA->id,
        'patient_id' => $patientA->id,
        'amount' => '200.00',
        'paid_at' => now(),
    ]);

    // Clinic B: 9999.00 — must NOT bleed into clinic A's revenue
    Transaction::factory()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'amount' => '9999.00',
        'paid_at' => now(),
    ]);

    $this->actingAs($ownerA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.revenue.today_collected', '200.00')
        );
});

// ---------------------------------------------------------------------------
// Props contract shape
// ---------------------------------------------------------------------------

it('stats.appointments block carries today, pending, this_week integer keys', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('stats.appointments.today')
            ->has('stats.appointments.pending')
            ->has('stats.appointments.this_week')
            ->where('stats.appointments.today', fn ($v) => is_int($v))
            ->where('stats.appointments.pending', fn ($v) => is_int($v))
            ->where('stats.appointments.this_week', fn ($v) => is_int($v))
        );
});

it('stats.revenue block carries today_collected (string) and currency (string) keys', function (): void {
    ['owner' => $owner] = dsSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('stats.revenue.today_collected')
            ->has('stats.revenue.currency')
            ->where('stats.revenue.today_collected', fn ($v) => is_string($v))
            ->where('stats.revenue.currency', fn ($v) => is_string($v))
        );
});

// ---------------------------------------------------------------------------
// Takvim özet — today_schedule feed (dedicated, full-day, not forward-only)
// ---------------------------------------------------------------------------

it('today_schedule is null for a user without appointments.viewAny', function (): void {
    ['clinic' => $clinic] = dsSetup();

    $noRole = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $noRole->assignRole('patient');
    $noRole->unsetRelation('roles');
    $noRole->unsetRelation('permissions');

    $this->actingAs($noRole)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('stats.today_schedule', null));
});

it('today_schedule includes an earlier-today appointment that already passed (Completed)', function (): void {
    // The regression: a forward-only (starts_at >= now) feed would drop this. At any time
    // after 09:00 clinic-local this Completed 09:00 appointment must still appear in the panel.
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $todayStart = dsTodayStartUtc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayStart,
        'ends_at' => $todayStart->copy()->addMinutes(30),
        'status' => AppointmentStatus::Completed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('stats.today_schedule', 1)
            ->where('stats.today_schedule.0.status', AppointmentStatus::Completed->value)
        );
});

it('today_schedule includes an Arrived (in-progress) appointment', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $todayStart = dsTodayStartUtc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayStart,
        'ends_at' => $todayStart->copy()->addMinutes(30),
        'status' => AppointmentStatus::Arrived,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('stats.today_schedule', 1)
            ->where('stats.today_schedule.0.status', AppointmentStatus::Arrived->value)
        );
});

it('today_schedule is not capped at the upcoming-widget limit (returns the full day)', function (): void {
    // The other regression: >N upcoming today rows would be truncated by the upcoming feed.
    // The dedicated feed has no small-N cap — all 8 of today's appointments must show.
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $base = dsTodayStartUtc()->copy()->addHours(8);

    for ($i = 0; $i < 8; $i++) {
        $startsAt = $base->copy()->addMinutes($i * 30);
        Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('stats.today_schedule', 8));
});

it('today_schedule excludes cancelled and no-show appointments', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $todayStart = dsTodayStartUtc();

    foreach ([AppointmentStatus::Cancelled, AppointmentStatus::NoShow] as $status) {
        Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'starts_at' => $todayStart,
            'ends_at' => $todayStart->copy()->addMinutes(30),
            'status' => $status,
        ]);
    }

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('stats.today_schedule', 0));
});

it('today_schedule excludes appointments outside the clinic-local today', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $yesterday = dsTodayStartUtc()->copy()->subHours(2);
    $tomorrow = dsTodayEndUtc()->copy()->addHours(2);

    foreach ([$yesterday, $tomorrow] as $startsAt) {
        Appointment::factory()->create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('stats.today_schedule', 0));
});

it('today_schedule is doctor-scoped for a doctor without viewAll', function (): void {
    ['clinic' => $clinic, 'doctor' => $doctor, 'doctorUser' => $doctorUser, 'patient' => $patient] = dsSetup();
    dashRole($doctorUser, 'doctor', $clinic->id);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);

    $todayStart = dsTodayStartUtc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayStart,
        'ends_at' => $todayStart->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $otherDoctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayStart->copy()->addHour(),
        'ends_at' => $todayStart->copy()->addHour()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('stats.today_schedule', 1)
            ->where('stats.today_schedule.0.doctor_id', $doctor->id)
        );
});

it('today_schedule does not leak another clinic\'s appointments', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $otherClinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul', 'currency' => 'TRY']);
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $otherClinic->id]);
    $otherPatient = Patient::factory()->create(['clinic_id' => $otherClinic->id]);

    $todayStart = dsTodayStartUtc();

    Appointment::factory()->create([
        'clinic_id' => $otherClinic->id,
        'doctor_id' => $otherDoctor->id,
        'patient_id' => $otherPatient->id,
        'starts_at' => $todayStart,
        'ends_at' => $todayStart->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('stats.today_schedule', 0));
});

it('today_schedule row carries the contract-shaped keys', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient] = dsSetup();

    $todayStart = dsTodayStartUtc();

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $todayStart,
        'ends_at' => $todayStart->copy()->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('stats.today_schedule.0', fn ($row) => $row
                ->where('id', fn ($v) => is_int($v))
                ->where('patient_id', $patient->id)
                ->has('patient_name')
                ->where('doctor_id', $doctor->id)
                ->has('doctor_name')
                ->has('service_name')
                ->where('status', AppointmentStatus::Confirmed->value)
                ->where('is_walk_in', fn ($v) => is_bool($v))
                ->has('starts_at')
            )
        );
});
