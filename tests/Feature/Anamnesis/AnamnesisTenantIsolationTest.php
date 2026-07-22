<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PodiatryAnamnesis;
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
 * Assign a clinic-scoped role (tenant-isolation tests).
 */
function atiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A clinic + patient with a filled anamnesis. Vertical is irrelevant here — every
 * assertion in this file is a cross-clinic 404 via route-model-binding ClinicScope,
 * resolved before the PUT handler's vertical guard ever runs.
 *
 * @return array{clinic: Clinic, patient: Patient, anamnesis: PodiatryAnamnesis}
 */
function atiSetup(): array
{
    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $anamnesis = PodiatryAnamnesis::create(['blood_type' => 'A+', 'allergies' => 'Penisilin']);
    $patient->anamnesis()->associate($anamnesis);
    $patient->save();

    return compact('clinic', 'patient', 'anamnesis');
}

it('clinic B doctor gets 404 on PUT of a clinic A patient anamnesis', function (): void {
    $setupA = atiSetup();
    $clinicB = Clinic::factory()->create();

    $doctorB = User::factory()->create();
    atiRole($doctorB, 'doctor', $clinicB->id);

    $this->actingAs($doctorB)
        ->put(route('patients.anamnesis.update', $setupA['patient']), ['blood_type' => 'B-'])
        ->assertNotFound();
});

it('cross-clinic PUT does not mutate the target patient anamnesis', function (): void {
    $setupA = atiSetup();
    $clinicB = Clinic::factory()->create();

    $doctorB = User::factory()->create();
    atiRole($doctorB, 'doctor', $clinicB->id);

    $this->actingAs($doctorB)
        ->put(route('patients.anamnesis.update', $setupA['patient']), ['blood_type' => 'B-'])
        ->assertNotFound();

    expect($setupA['patient']->anamnesis->fresh()->blood_type)->toBe('A+');
});

it('clinic A user cannot GET clinic B patient anamnesis PDF (404)', function (): void {
    $setupA = atiSetup();
    $setupB = atiSetup();

    $doctorA = User::factory()->create();
    atiRole($doctorA, 'doctor', $setupA['clinic']->id);

    $this->actingAs($doctorA)
        ->get(route('patients.anamnesis.pdf', $setupB['patient']))
        ->assertNotFound();
});

it("clinic A's patients.show read prop never exposes clinic B's anamnesis data", function (): void {
    $setupA = atiSetup();
    $setupB = atiSetup();

    $doctorA = User::factory()->create();
    atiRole($doctorA, 'doctor', $setupA['clinic']->id);

    $this->actingAs($doctorA)
        ->get(route('patients.show', $setupA['patient']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('anamnesis.blood_type', 'A+'));

    // Same user has no access to clinic B's patient at all.
    $this->actingAs($doctorA)
        ->get(route('patients.show', $setupB['patient']))
        ->assertNotFound();
});
