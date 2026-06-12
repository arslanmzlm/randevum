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
function pasRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

function pasAppointment(Clinic $clinic, Patient $patient, Doctor $doctor, string $startsAt): Appointment
{
    return Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $startsAt,
        'ends_at' => Carbon\Carbon::parse($startsAt)->addMinutes(30),
        'status' => AppointmentStatus::Confirmed,
    ]);
}

it('owner sees every doctor\'s appointments for the patient, newest first', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pasRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $older = pasAppointment($clinic, $patient, $doctorA, now()->subDays(5)->toDateTimeString());
    $newer = pasAppointment($clinic, $patient, $doctorB, now()->addDays(2)->toDateTimeString());

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->has('appointments', 2)
            ->where('appointments.0.id', $newer->id)
            ->where('appointments.1.id', $older->id)
            ->where('appointments.0.status', 'confirmed')
            ->has('appointments.0.doctor_name')
            ->has('appointments.0.starts_at')
            ->has('appointments.0.ends_at')
        );
});

it('a doctor without viewAll sees only their own appointments for the patient', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    pasRole($doctorUser, 'doctor', $clinic->id);
    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $own = pasAppointment($clinic, $patient, $ownDoctor, now()->addDay()->toDateTimeString());
    pasAppointment($clinic, $patient, $otherDoctor, now()->addDays(3)->toDateTimeString());

    $this->actingAs($doctorUser)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('appointments', 1)
            ->where('appointments.0.id', $own->id)
        );
});

it('a doctor-role user without a doctor profile gets an empty appointments list', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    pasRole($doctorUser, 'doctor', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    pasAppointment($clinic, $patient, $doctor, now()->addDay()->toDateTimeString());

    $this->actingAs($doctorUser)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments', 0));
});

it('another patient\'s appointments never appear on the page', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pasRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $otherPatient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    pasAppointment($clinic, $otherPatient, $doctor, now()->addDay()->toDateTimeString());

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointments', 0));
});
