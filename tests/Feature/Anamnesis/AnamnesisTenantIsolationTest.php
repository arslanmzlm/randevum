<?php

use App\Models\Anamnesis;
use App\Models\Clinic;
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
 * assertion in this file is a cross-clinic 404 via route-model-binding ClinicScope.
 *
 * @return array{clinic: Clinic, patient: Patient, anamnesis: Anamnesis}
 */
function atiSetup(): array
{
    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $anamnesis = Anamnesis::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'blood_type' => 'A+',
        'allergies' => 'Penisilin',
    ]);

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

    expect($setupA['anamnesis']->fresh()->blood_type)->toBe('A+');
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

it("Anamnesis::query() under clinic B's context never returns clinic A's row (ClinicScope)", function (): void {
    $setupA = atiSetup();
    $clinicB = Clinic::factory()->create();

    app(ClinicContext::class)->set($clinicB->id);

    expect(Anamnesis::query()->find($setupA['anamnesis']->id))->toBeNull()
        ->and(Anamnesis::query()->count())->toBe(0);
});

it('a create under clinic A context stamps clinic_id = A even when the payload omits it', function (): void {
    $clinicA = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    app(ClinicContext::class)->set($clinicA->id);

    $anamnesis = Anamnesis::create(['patient_id' => $patient->id, 'blood_type' => 'A+']);

    expect($anamnesis->clinic_id)->toBe($clinicA->id);
});
