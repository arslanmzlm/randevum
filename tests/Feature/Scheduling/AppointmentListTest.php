<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
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
                ->has('treatment_id')
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
            ->has('query.filter.service_id')
            ->has('query.filter.appointment_type_id')
            ->has('query.filter.start_date')
            ->has('query.filter.end_date')
            ->has('query.sort')
            ->has('query.per_page')
            ->where('query.filter.doctor_id', [])
        );
});

it('services and appointmentTypes props are rendered on the index', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    Service::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Muayene', 'is_active' => true]);
    AppointmentType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Kontrol']);

    $this->actingAs($owner)
        ->get(route('appointments.index'))
        ->assertInertia(fn ($page) => $page
            ->has('services', 1)
            ->where('services.0.name', 'Muayene')
            ->has('appointmentTypes', 1)
            ->where('appointmentTypes.0.name', 'Kontrol')
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

it('doctor filter with a comma-joined list narrows results to both doctors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);
    $doctorUserC = User::factory()->create();
    $doctorC = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserC->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    alAppointment($clinic, $doctorA, $patient);
    alAppointment($clinic, $doctorB, $patient);
    alAppointment($clinic, $doctorC, $patient);

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['doctor_id' => "{$doctorA->id},{$doctorB->id}"]]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 2));
});

it('service filter with the none sentinel returns only appointments with no service, and value-or-none returns both', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create(['clinic_id' => $clinic->id]);

    alAppointment($clinic, $doctor, $patient, ['service_id' => $service->id]);
    alAppointment($clinic, $doctor, $patient, ['service_id' => null]);

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['service_id' => 'none']]))
        ->assertInertia(fn ($page) => $page
            ->has('appointments.data', 1)
            ->where('appointments.data.0.service_name', null)
        );

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['service_id' => "{$service->id},none"]]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 2));
});

it('appointment_type filter with the none sentinel returns only appointments with no type, and value-or-none returns both', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $type = AppointmentType::factory()->create(['clinic_id' => $clinic->id]);

    alAppointment($clinic, $doctor, $patient, ['appointment_type_id' => $type->id]);
    alAppointment($clinic, $doctor, $patient, ['appointment_type_id' => null]);

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['appointment_type_id' => 'none']]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 1));

    $this->actingAs($owner)
        ->get(route('appointments.index', ['filter' => ['appointment_type_id' => "{$type->id},none"]]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 2));
});

it('a non-viewAll doctor passing another doctors id in the doctor filter still sees only their own', function (): void {
    $clinic = Clinic::factory()->create();

    $doctorUser = User::factory()->create();
    alRole($doctorUser, 'doctor', $clinic->id);
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    alAppointment($clinic, $ownDoctor, $patient);
    alAppointment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->get(route('appointments.index', ['filter' => ['doctor_id' => (string) $otherDoctor->id]]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 0));
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

it('tenant A user using a comma-joined doctor_id filter that includes tenant B ids cannot retrieve tenant B appointments', function (): void {
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

    $this->actingAs($ownerA)
        ->get(route('appointments.index', ['filter' => ['doctor_id' => "{$doctorA->id},{$doctorB->id}"]]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 1));
});

it('tenant A user using tenant B service_id / appointment_type_id filters gets zero tenant A rows', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    alRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $serviceB = Service::factory()->create(['clinic_id' => $clinicB->id]);
    $typeB = AppointmentType::factory()->create(['clinic_id' => $clinicB->id]);

    alAppointment($clinicA, $doctorA, $patientA);

    $this->actingAs($ownerA)
        ->get(route('appointments.index', ['filter' => ['service_id' => (string) $serviceB->id]]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 0));

    $this->actingAs($ownerA)
        ->get(route('appointments.index', ['filter' => ['appointment_type_id' => (string) $typeB->id]]))
        ->assertInertia(fn ($page) => $page->has('appointments.data', 0));
});

// ---------------------------------------------------------------------------
// Soft-deleted patient/doctor — the appointment row outlives them
// ---------------------------------------------------------------------------

it('does not 500 and still shows names when an appointment\'s patient and doctor are soft-deleted', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    alRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // starts_at in the past: keeps this appointment out of the "upcoming appointments" header
    // widget's own soft-delete gap (a separate, out-of-scope bug in AppointmentService::upcomingFor),
    // so this test isolates the /appointments list fix under review.
    $appointment = alAppointment($clinic, $doctor, $patient, [
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(30),
    ]);

    $patient->delete();
    $doctor->delete();

    $this->actingAs($owner)
        ->get(route('appointments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('appointments.data', 1)
            ->where('appointments.data.0.id', $appointment->id)
            ->where('appointments.data.0.patient_name', trim($patient->first_name.' '.$patient->last_name))
            ->where('appointments.data.0.doctor_name', $doctor->display_name)
        );
});
