<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Support\ClinicContext;
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
function uaRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Create a future Confirmed appointment for the given clinic/doctor/patient.
 *
 * @param  array<string, mixed>  $overrides
 */
function uaFutureAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
{
    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /appointments/upcoming', function (): void {
    $this->get(route('appointments.upcoming'))
        ->assertRedirect(route('login'));
});

it('user without appointments.viewAny gets 403 on GET /appointments/upcoming', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('appointments.upcoming'))
        ->assertForbidden();
});

it('owner can access GET /appointments/upcoming and receives json', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointments.upcoming'))
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('doctor role user can access GET /appointments/upcoming', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    uaRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->get(route('appointments.upcoming'))
        ->assertOk()
        ->assertJsonStructure(['data']);
});

it('assistant role user can access GET /appointments/upcoming', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    uaRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('appointments.upcoming'))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Status filtering — only Confirmed + Rescheduled; excludes past
// ---------------------------------------------------------------------------

it('returns only Confirmed and Rescheduled future appointments', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Confirmed]);
    uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Rescheduled]);
    uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Arrived]);
    uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Cancelled]);
    uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Completed]);
    uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::NoShow]);
    uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Pending]);

    $this->actingAs($owner)
        ->get(route('appointments.upcoming', ['limit' => 20]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('excludes past appointments even when status is Confirmed or Rescheduled', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // Future — must appear.
    $future = uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Confirmed]);

    // Past — must be excluded regardless of status.
    Appointment::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
    ]);
    Appointment::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Rescheduled,
    ]);

    $this->actingAs($owner)
        ->get(route('appointments.upcoming', ['limit' => 20]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $future->id);
});

// ---------------------------------------------------------------------------
// Ordering — ascending starts_at
// ---------------------------------------------------------------------------

it('returns appointments in ascending starts_at order', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $later = uaFutureAppointment($clinic, $doctor, $patient, [
        'starts_at' => now()->addDays(5),
        'ends_at' => now()->addDays(5)->addMinutes(30),
    ]);
    $sooner = uaFutureAppointment($clinic, $doctor, $patient, [
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
    ]);

    $this->actingAs($owner)
        ->get(route('appointments.upcoming', ['limit' => 20]))
        ->assertOk()
        ->assertJsonPath('data.0.id', $sooner->id)
        ->assertJsonPath('data.1.id', $later->id);
});

// ---------------------------------------------------------------------------
// Limit — honour param, config default, validation
// ---------------------------------------------------------------------------

it('honours the limit query parameter', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    for ($i = 1; $i <= 10; $i++) {
        uaFutureAppointment($clinic, $doctor, $patient, [
            'starts_at' => now()->addDays($i),
            'ends_at' => now()->addDays($i)->addMinutes(30),
        ]);
    }

    $this->actingAs($owner)
        ->get(route('appointments.upcoming', ['limit' => 3]))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

it('uses the config default limit when limit is omitted', function (): void {
    $defaultLimit = config('platform.appointment.upcoming_widget_limit');
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    for ($i = 1; $i <= $defaultLimit + 3; $i++) {
        uaFutureAppointment($clinic, $doctor, $patient, [
            'starts_at' => now()->addDays($i),
            'ends_at' => now()->addDays($i)->addMinutes(30),
        ]);
    }

    $this->actingAs($owner)
        ->get(route('appointments.upcoming'))
        ->assertOk()
        ->assertJsonCount($defaultLimit, 'data');
});

it('rejects limit greater than 20 with 422', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);

    // getJson sends Accept: application/json so FormRequest returns 422 (not a redirect).
    $this->actingAs($owner)
        ->getJson(route('appointments.upcoming', ['limit' => 21]))
        ->assertUnprocessable();
});

it('rejects limit less than 1 with 422', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(route('appointments.upcoming', ['limit' => 0]))
        ->assertUnprocessable();
});

// ---------------------------------------------------------------------------
// Doctor scoping
// ---------------------------------------------------------------------------

it('viewAll user (owner) sees clinic-wide upcoming appointments', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    uaFutureAppointment($clinic, $doctorA, $patient);
    uaFutureAppointment($clinic, $doctorB, $patient);

    $this->actingAs($owner)
        ->get(route('appointments.upcoming', ['limit' => 20]))
        ->assertOk()
        ->assertJsonCount(2, 'data');
});

it('doctor without viewAll sees only their own upcoming appointments', function (): void {
    $clinic = Clinic::factory()->create();

    $doctorUser = User::factory()->create();
    uaRole($doctorUser, 'doctor', $clinic->id);
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    uaFutureAppointment($clinic, $ownDoctor, $patient, [
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addMinutes(30),
    ]);
    uaFutureAppointment($clinic, $ownDoctor, $patient, [
        'starts_at' => now()->addDays(2),
        'ends_at' => now()->addDays(2)->addMinutes(30),
    ]);
    uaFutureAppointment($clinic, $otherDoctor, $patient); // must be hidden from $doctorUser

    $this->actingAs($doctorUser)
        ->get(route('appointments.upcoming', ['limit' => 20]))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.doctor_id', $ownDoctor->id)
        ->assertJsonPath('data.1.doctor_id', $ownDoctor->id);
});

it('user with no doctor profile and no viewAll gets empty list', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    uaRole($doctorUser, 'doctor', $clinic->id);
    // No Doctor profile linked to $doctorUser.

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    uaFutureAppointment($clinic, $otherDoctor, $patient);

    $this->actingAs($doctorUser)
        ->get(route('appointments.upcoming', ['limit' => 20]))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

// ---------------------------------------------------------------------------
// DTO shape — all keys present, starts_at is ISO-8601 UTC, status is string value
// ---------------------------------------------------------------------------

it('response items carry the full DTO shape and starts_at is ISO-8601', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Zeynep',
        'last_name' => 'Kaya',
    ]);

    $appointment = uaFutureAppointment($clinic, $doctor, $patient, [
        'status' => AppointmentStatus::Confirmed,
        'is_walk_in' => true,
    ]);

    $item = $this->actingAs($owner)
        ->get(route('appointments.upcoming', ['limit' => 5]))
        ->assertOk()
        ->json('data.0');

    expect($item)->toHaveKeys([
        'id', 'patient_id', 'patient_name',
        'doctor_id', 'doctor_name', 'service_name',
        'appointment_type', 'status', 'is_walk_in', 'starts_at',
    ]);

    expect($item['id'])->toBe($appointment->id);
    expect($item['patient_id'])->toBe($patient->id);
    expect($item['patient_name'])->toBe('Zeynep Kaya');
    expect($item['doctor_id'])->toBe($doctor->id);
    expect($item['is_walk_in'])->toBeTrue();
    expect($item['status'])->toBe('confirmed');
    expect($item['appointment_type'])->toBeNull();
    // ISO-8601 with timezone designator (+00:00 / Z).
    expect($item['starts_at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+Z]/');
});

it('rescheduled appointment status value is rescheduled in the DTO', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    uaFutureAppointment($clinic, $doctor, $patient, ['status' => AppointmentStatus::Rescheduled]);

    $this->actingAs($owner)
        ->get(route('appointments.upcoming', ['limit' => 5]))
        ->assertOk()
        ->assertJsonPath('data.0.status', 'rescheduled');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user cannot see clinic B appointments via the endpoint', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    uaRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $ownAppointment = uaFutureAppointment($clinicA, $doctorA, $patientA);
    uaFutureAppointment($clinicB, $doctorB, $patientB); // must not bleed through
    uaFutureAppointment($clinicB, $doctorB, $patientB); // must not bleed through

    $this->actingAs($ownerA)
        ->get(route('appointments.upcoming', ['limit' => 20]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownAppointment->id);
});

it('clinic B appointments do not appear in the shared Inertia prop for a clinic A user', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    uaRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $ownAppointment = uaFutureAppointment($clinicA, $doctorA, $patientA);
    uaFutureAppointment($clinicB, $doctorB, $patientB); // must not bleed into the shared prop

    $this->actingAs($ownerA)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('upcomingAppointments', 1)
            ->where('upcomingAppointments.0.id', $ownAppointment->id)
        );
});

// ---------------------------------------------------------------------------
// Shared prop — upcomingAppointments in every authenticated Inertia response
// ---------------------------------------------------------------------------

it('authenticated Inertia page includes upcomingAppointments with the viewer\'s rows', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = uaFutureAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('upcomingAppointments', 1)
            ->where('upcomingAppointments.0.id', $appointment->id)
        );
});

it('shared prop is empty array for a user without appointments.viewAny', function (): void {
    // No clinic-scoped role → no permissions → viewAny denied → [] shared prop.
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->where('upcomingAppointments', [])
        );
});

it('shared prop scopes to only the authenticated doctor\'s own appointments', function (): void {
    $clinic = Clinic::factory()->create();

    $doctorUser = User::factory()->create();
    uaRole($doctorUser, 'doctor', $clinic->id);
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $ownAppointment = uaFutureAppointment($clinic, $ownDoctor, $patient);
    uaFutureAppointment($clinic, $otherDoctor, $patient); // excluded

    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('upcomingAppointments', 1)
            ->where('upcomingAppointments.0.id', $ownAppointment->id)
        );
});

it('past appointments are excluded from the shared prop', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $future = uaFutureAppointment($clinic, $doctor, $patient);

    // Past Confirmed — must NOT appear in the shared prop.
    Appointment::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('upcomingAppointments', 1)
            ->where('upcomingAppointments.0.id', $future->id)
        );
});

it('shared prop includes all expected DTO keys', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    uaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    uaFutureAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page
            ->has('upcomingAppointments.0.id')
            ->has('upcomingAppointments.0.patient_id')
            ->has('upcomingAppointments.0.patient_name')
            ->has('upcomingAppointments.0.doctor_id')
            ->has('upcomingAppointments.0.doctor_name')
            ->has('upcomingAppointments.0.service_name')
            ->has('upcomingAppointments.0.appointment_type')
            ->has('upcomingAppointments.0.status')
            ->has('upcomingAppointments.0.is_walk_in')
            ->has('upcomingAppointments.0.starts_at')
        );
});
