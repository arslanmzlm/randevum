<?php

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
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function ptiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Two-clinic fixture. Returns [$clinicA, $ownerA, $clinicB, $patientFromB].
 *
 * @return array{0: Clinic, 1: User, 2: Clinic, 3: Patient}
 */
function twoPatientTenantFixture(): array
{
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    ptiRole($ownerA, 'owner', $clinicA->id);

    $patientB = Patient::factory()->create([
        'clinic_id' => $clinicB->id,
        'first_name' => 'Clinic B Patient',
    ]);

    return [$clinicA, $ownerA, $clinicB, $patientB];
}

// ---------------------------------------------------------------------------
// GET /patients — index read isolation
// ---------------------------------------------------------------------------

it("clinic A's index never exposes clinic B's patients", function (): void {
    [$clinicA, $ownerA, $clinicB, $patientB] = twoPatientTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 0));
});

it("clinic A's index response body does not contain clinic B's patient name", function (): void {
    [$clinicA, $ownerA, $clinicB, $patientB] = twoPatientTenantFixture();

    $response = $this->actingAs($ownerA)->get(route('patients.index'));

    expect($response->content())->not->toContain($patientB->first_name);
});

it("owner A sees only clinic A's patients when both clinics have patients", function (): void {
    [$clinicA, $ownerA, $clinicB, $patientB] = twoPatientTenantFixture();

    $patientA = Patient::factory()->create([
        'clinic_id' => $clinicA->id,
        'first_name' => 'Clinic A Patient',
        'phone' => '05388888888',
    ]);

    $this->actingAs($ownerA)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('patients.meta.total', 1)
            ->where('patients.data.0.id', $patientA->id)
        );
});

// ---------------------------------------------------------------------------
// GET /patients/{patient} — cross-clinic show is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on GET /patients/{patient} for a clinic B patient', function (): void {
    [$clinicA, $ownerA, $clinicB, $patientB] = twoPatientTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('patients.show', $patientB))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// GET /patients/{patient}/edit — cross-clinic edit is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on GET /patients/{patient}/edit for a clinic B patient', function (): void {
    [$clinicA, $ownerA, $clinicB, $patientB] = twoPatientTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('patients.edit', $patientB))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// PUT /patients/{patient} — cross-clinic update is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on PUT /patients/{patient} for a clinic B patient', function (): void {
    [$clinicA, $ownerA, $clinicB, $patientB] = twoPatientTenantFixture();

    $originalName = $patientB->first_name;

    $this->actingAs($ownerA)
        ->put(route('patients.update', $patientB), [
            'first_name' => 'Hacked',
            'last_name' => 'Name',
            'phone' => '05312345678',
            'notification_enabled' => true,
            'is_legacy' => false,
        ])
        ->assertNotFound();

    expect($patientB->fresh()->first_name)->toBe($originalName);
});

// ---------------------------------------------------------------------------
// DELETE /patients/{patient} — cross-clinic destroy is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on DELETE /patients/{patient} for a clinic B patient', function (): void {
    [$clinicA, $ownerA, $clinicB, $patientB] = twoPatientTenantFixture();

    $this->actingAs($ownerA)
        ->delete(route('patients.destroy', $patientB))
        ->assertNotFound();

    // Clinic B's patient must still exist (not soft-deleted)
    expect(Patient::withoutGlobalScopes()->find($patientB->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// POST /patients/{patient}/restore — cross-clinic restore is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 when trying to restore a clinic B trashed patient', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    ptiRole($ownerA, 'owner', $clinicA->id);

    $trashedB = Patient::factory()->trashed()->create([
        'clinic_id' => $clinicB->id,
    ]);

    $this->actingAs($ownerA)
        ->post(route('patients.restore', ['patient' => $trashedB->id]))
        ->assertNotFound();

    // Clinic B's trashed patient must remain trashed
    expect(Patient::withoutGlobalScopes()->find($trashedB->id)->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// store — created patient is scoped to the active clinic only
// ---------------------------------------------------------------------------

it('a patient created by owner A is not visible to owner B', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    ptiRole($ownerA, 'owner', $clinicA->id);
    ptiRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)
        ->post(route('patients.store'), [
            'first_name' => 'Owner A Patient',
            'last_name' => 'Test',
            'phone' => '05312345678',
            'notification_enabled' => true,
            'is_legacy' => false,
        ]);

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($ownerB)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 0));
});
