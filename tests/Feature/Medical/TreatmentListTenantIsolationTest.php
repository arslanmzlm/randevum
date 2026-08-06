<?php

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
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

function tliRole(User $user, string $role, int $clinicId): void
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

it("clinic A's treatments list never exposes clinic B's treatments", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    tliRole($ownerA, 'owner', $clinicA->id);

    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $treatmentA = Treatment::factory()->create([
        'clinic_id' => $clinicA->id,
        'doctor_id' => $doctorA->id,
        'patient_id' => $patientA->id,
    ]);

    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    Treatment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'patient_id' => $patientB->id,
    ]);

    $this->actingAs($ownerA)
        ->get(route('treatments.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatments.meta.total', 1)
            ->where('treatments.data.0.id', $treatmentA->id)
        );
});
