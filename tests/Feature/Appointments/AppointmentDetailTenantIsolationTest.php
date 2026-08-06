<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\StatusLog;
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

function adiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A user gets 404 on GET /appointments/{id} for a clinic B appointment', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    adiRole($ownerA, 'owner', $clinicA->id);

    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $appointmentB = Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($ownerA)
        ->get(route('appointments.show', $appointmentB))
        ->assertNotFound();
});

it('a status log belonging to clinic B never appears on a clinic A appointment with the same loggable_id', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    adiRole($ownerA, 'owner', $clinicA->id);

    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $appointmentA = Appointment::factory()->create([
        'clinic_id' => $clinicA->id,
        'doctor_id' => $doctorA->id,
        'patient_id' => $patientA->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Spoofed row: same loggable_id as appointment A, but tagged to clinic B —
    // BelongsToClinic's ClinicScope must exclude it from clinic A's read.
    StatusLog::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'loggable_type' => 'appointment',
        'loggable_id' => $appointmentA->id,
        'from_status' => 'confirmed',
        'to_status' => 'cancelled',
        'transitioned_at' => now(),
        'by_user_id' => null,
        'reason' => 'clinic B leak attempt',
    ]);

    $this->actingAs($ownerA)
        ->get(route('appointments.show', $appointmentA))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('statusLogs', []));
});
