<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
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
function alRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Create an appointment for the given clinic/doctor/patient.
 *
 * @param  array<string, mixed>  $overrides
 */
function alAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
{
    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /appointments', function (): void {
    $this->get(route('appointments.index'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /appointments and the Index component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointments/Index')
            ->has('appointments')
            ->has('doctors')
            ->has('query')
        );
});

it('user without appointments.viewAny gets 403', function (): void {
    // A user with no clinic-scoped role has no permissions.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('appointments.index'))
        ->assertForbidden();
});

it('doctor role user can access GET /appointments (has viewAny)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    alRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->get(route('appointments.index'))
        ->assertOk();
});

it('assistant role user can access GET /appointments (has viewAny)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    alRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('appointments.index'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Prop shape & listing
// ---------------------------------------------------------------------------

it('appointments prop contains the clinic appointments with the correct resource fields', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    alAppointment($clinic, $doctor, $patient, ['is_walk_in' => true]);

    $this->actingAs($owner)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page
            ->has('appointments.data', 1)
            ->has('appointments.data.0', fn ($item) => $item
                ->has('id')
                ->has('patient_id')
                ->has('patient_name')
                ->has('doctor_id')
                ->has('doctor_name')
                ->has('service_name')
                ->has('appointment_type')
                ->has('status')
                ->has('is_walk_in')
                ->has('starts_at')
                ->has('ends_at')
                ->where('is_walk_in', true)
            )
        );
});

it('doctors prop lists active doctors for a user with appointments.viewAll', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id, 'is_active' => true]);

    $this->actingAs($owner)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page
            ->has('doctors', 1)
            ->has('doctors.0.id')
            ->has('doctors.0.display_name')
        );
});

it('doctors prop is empty for a user without appointments.viewAll (doctor role)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    alRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->where('doctors', []));
});

it('query prop contains the expected filter/sort/per_page keys', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page
            ->has('query.filter.search')
            ->has('query.filter.status')
            ->has('query.filter.doctor_id')
            ->has('query.filter.start_date')
            ->has('query.filter.end_date')
            ->has('query.sort')
            ->has('query.per_page')
        );
});

// ---------------------------------------------------------------------------
// Filters
// ---------------------------------------------------------------------------

it('status filter narrows results to the requested statuses', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    alAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Confirmed]);
    alAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Arrived]);
    alAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Cancelled]);

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['status' => 'confirmed,arrived']]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 2));
});

it('doctor filter narrows results to the specified doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    alAppointment($clinic, $doctorA, $patient);
    alAppointment($clinic, $doctorB, $patient);

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['doctor_id' => $doctorA->id]]))
        ->assertInertia(fn ($page) => $page
            ->has('appointments.data', 1)
            ->where('appointments.data.0.doctor_id', $doctorA->id)
        );
});

it('date range filter narrows results to appointments within the given clinic-local days', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    alAppointment($clinic, $doctor, $patient, [
        'starts_at' => Carbon::parse('2026-03-15 10:00:00', 'Europe/Istanbul')->utc(),
        'ends_at' => Carbon::parse('2026-03-15 10:30:00', 'Europe/Istanbul')->utc(),
    ]);

    alAppointment($clinic, $doctor, $patient, [
        'starts_at' => Carbon::parse('2026-04-20 10:00:00', 'Europe/Istanbul')->utc(),
        'ends_at' => Carbon::parse('2026-04-20 10:30:00', 'Europe/Istanbul')->utc(),
    ]);

    $this->actingAs($owner)
        ->get(route('appointments.index', [
            'filter' => ['start_date' => '2026-03-01', 'end_date' => '2026-03-31'],
        ]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 1));
});

it('patient name search narrows results via the patient relation', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $matchPatient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Zeynep', 'last_name' => 'Kaya', 'phone' => '05311111111']);
    $otherPatient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'last_name' => 'Demir', 'phone' => '05322222222']);

    alAppointment($clinic, $doctor, $matchPatient);
    alAppointment($clinic, $doctor, $otherPatient);

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['search' => 'Zeynep']]))
        ->assertInertia(fn ($page) => $page
            ->has('appointments.data', 1)
            ->where('appointments.data.0.patient_name', 'Zeynep Kaya')
        );
});

it('patient last name search also matches', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $matchPatient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ali', 'last_name' => 'Yılmaz', 'phone' => '05311111111']);
    $otherPatient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Veli', 'last_name' => 'Demir', 'phone' => '05322222222']);

    alAppointment($clinic, $doctor, $matchPatient);
    alAppointment($clinic, $doctor, $otherPatient);

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['search' => 'Yılmaz']]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 1));
});

// ---------------------------------------------------------------------------
// Doctor scoping
// ---------------------------------------------------------------------------

it('doctor role user sees only their own appointments', function (): void {
    $clinic = Clinic::factory()->create();

    $doctorUser = User::factory()->create();
    alRole($doctorUser, 'doctor', $clinic->id);
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    alAppointment($clinic, $ownDoctor, $patient);
    alAppointment($clinic, $ownDoctor, $patient);
    alAppointment($clinic, $otherDoctor, $patient); // must be hidden

    $this->actingAs($doctorUser)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 2));
});

it('a doctor user with no doctor profile receives an empty list', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    alRole($doctorUser, 'doctor', $clinic->id);
    // No Doctor profile linked to $doctorUser.

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    alAppointment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 0));
});

it('owner user (has viewAll) sees appointments of all doctors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    alAppointment($clinic, $doctorA, $patient);
    alAppointment($clinic, $doctorB, $patient);

    $this->actingAs($owner)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 2));
});

// ---------------------------------------------------------------------------
// Default sort + sort override
// ---------------------------------------------------------------------------

it('default order is starts_at descending (newest first)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $earlier = alAppointment($clinic, $doctor, $patient, [
        'starts_at' => now()->addDays(1),
        'ends_at' => now()->addDays(1)->addMinutes(30),
    ]);
    $later = alAppointment($clinic, $doctor, $patient, [
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5)->addMinutes(30),
    ]);

    $this->actingAs($owner)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page
            ->where('appointments.data.0.id', $later->id)
            ->where('appointments.data.1.id', $earlier->id)
        );
});

it('sort=starts_at orders ascending when no minus prefix', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $earlier = alAppointment($clinic, $doctor, $patient, [
        'starts_at' => now()->addDays(1),
        'ends_at' => now()->addDays(1)->addMinutes(30),
    ]);
    $later = alAppointment($clinic, $doctor, $patient, [
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5)->addMinutes(30),
    ]);

    $this->actingAs($owner)
        ->get(route('appointments.index', ['sort' => 'starts_at']))
        ->assertInertia(fn ($page) => $page
            ->where('appointments.data.0.id', $earlier->id)
            ->where('appointments.data.1.id', $later->id)
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('tenant A user only sees tenant A appointments — tenant B rows are invisible', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    alRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    alAppointment($clinicA, $doctorA, $patientA);
    alAppointment($clinicA, $doctorA, $patientA);
    Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
    ]);

    $this->actingAs($ownerA)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 2));
});

it('tenant A user using doctor_id filter cannot retrieve tenant B appointments via ClinicScope', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    alRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    alAppointment($clinicA, $doctorA, $patientA);
    Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
    ]);

    // Passing clinic B's doctor_id as filter must not leak B's appointments.
    $this->actingAs($ownerA)
        ->get(route('appointments.index', ['filter' => ['doctor_id' => $doctorB->id]]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 0));
});
