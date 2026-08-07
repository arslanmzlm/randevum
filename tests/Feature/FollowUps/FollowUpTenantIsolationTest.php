<?php

use App\Models\Clinic;
use App\Models\FollowUp;
use App\Models\FollowUpType;
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
 * Assign a clinic-scoped role (follow-up tenant isolation tests).
 */
function ftiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinicA: Clinic, ownerA: User, clinicB: Clinic, ownerB: User}
 */
function ftiTwoClinics(): array
{
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    ftiRole($ownerA, 'owner', $clinicA->id);

    $ownerB = User::factory()->create();
    ftiRole($ownerB, 'owner', $clinicB->id);

    return compact('clinicA', 'ownerA', 'clinicB', 'ownerB');
}

// ---------------------------------------------------------------------------
// Mutating another clinic's follow-up is 404 (route-model binding via ClinicScope)
// ---------------------------------------------------------------------------

it("clinic A owner gets 404 completing clinic B's follow-up", function (): void {
    ['ownerA' => $ownerA, 'clinicB' => $clinicB] = ftiTwoClinics();

    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $followUpB = FollowUp::factory()->open()->create(['clinic_id' => $clinicB->id, 'patient_id' => $patientB->id]);

    $this->actingAs($ownerA)
        ->patch(route('follow-ups.complete', $followUpB), [])
        ->assertNotFound();

    expect(FollowUp::withoutGlobalScopes()->find($followUpB->id)->status->value)->toBe('open');
});

it("clinic A owner gets 404 cancelling clinic B's follow-up", function (): void {
    ['ownerA' => $ownerA, 'clinicB' => $clinicB] = ftiTwoClinics();

    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $followUpB = FollowUp::factory()->open()->create(['clinic_id' => $clinicB->id, 'patient_id' => $patientB->id]);

    $this->actingAs($ownerA)
        ->patch(route('follow-ups.cancel', $followUpB))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Creating a follow-up cannot reach across clinics (FormRequest Rule::exists scoping)
// ---------------------------------------------------------------------------

it("clinic A cannot create a follow-up for clinic B's patient", function (): void {
    ['ownerA' => $ownerA, 'clinicA' => $clinicA, 'clinicB' => $clinicB] = ftiTwoClinics();

    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $typeA = FollowUpType::factory()->create(['clinic_id' => $clinicA->id]);

    $this->actingAs($ownerA)
        ->post(route('follow-ups.store'), [
            'patient_id' => $patientB->id,
            'follow_up_type_id' => $typeA->id,
            'due_date' => today()->addDays(2)->format('Y-m-d'),
        ])
        ->assertSessionHasErrors('patient_id');

    expect(FollowUp::withoutGlobalScopes()->where('patient_id', $patientB->id)->exists())->toBeFalse();
});

it("clinic A cannot create a follow-up using clinic B's follow_up_type_id", function (): void {
    ['ownerA' => $ownerA, 'clinicA' => $clinicA, 'clinicB' => $clinicB] = ftiTwoClinics();

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $typeB = FollowUpType::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->post(route('follow-ups.store'), [
            'patient_id' => $patientA->id,
            'follow_up_type_id' => $typeB->id,
            'due_date' => today()->addDays(2)->format('Y-m-d'),
        ])
        ->assertSessionHasErrors('follow_up_type_id');
});

// ---------------------------------------------------------------------------
// Follow-up type CRUD never touches another clinic's rows
// ---------------------------------------------------------------------------

it("clinic A's follow-up-types index never exposes clinic B's types", function (): void {
    ['ownerA' => $ownerA, 'clinicB' => $clinicB] = ftiTwoClinics();

    FollowUpType::factory()->create(['clinic_id' => $clinicB->id, 'name' => 'Clinic B Type']);

    $this->actingAs($ownerA)
        ->get(route('follow-up-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('followUpTypes.data', 0));
});

it("clinic A owner gets 404 updating clinic B's follow-up type", function (): void {
    ['ownerA' => $ownerA, 'clinicB' => $clinicB] = ftiTwoClinics();

    $typeB = FollowUpType::factory()->create(['clinic_id' => $clinicB->id, 'name' => 'Original']);

    $this->actingAs($ownerA)
        ->put(route('follow-up-types.update', $typeB), ['name' => 'Hacked', 'is_active' => true])
        ->assertNotFound();

    expect($typeB->fresh()->name)->toBe('Original');
});

it("clinic A owner gets 404 deleting clinic B's follow-up type", function (): void {
    ['ownerA' => $ownerA, 'clinicB' => $clinicB] = ftiTwoClinics();

    $typeB = FollowUpType::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->delete(route('follow-up-types.destroy', $typeB))
        ->assertNotFound();

    expect(FollowUpType::withoutGlobalScopes()->find($typeB->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Dashboard prop never leaks another clinic's rows
// ---------------------------------------------------------------------------

it("dashboard followUps prop never leaks clinic B's rows", function (): void {
    ['ownerA' => $ownerA, 'clinicB' => $clinicB] = ftiTwoClinics();

    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    FollowUp::factory()->open()->create(['clinic_id' => $clinicB->id, 'patient_id' => $patientB->id]);

    $this->actingAs($ownerA)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('followUps', []));
});
