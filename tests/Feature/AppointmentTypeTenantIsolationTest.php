<?php

use App\Models\AppointmentType;
use App\Models\Clinic;
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
function atIsoRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Two-tenant fixture: returns [$clinicA, $ownerA, $clinicB, $typeFromB].
 *
 * Owner A belongs to clinic A; the appointment type belongs to clinic B.
 *
 * @return array{0: Clinic, 1: User, 2: Clinic, 3: AppointmentType}
 */
function twoTypeTenantFixture(): array
{
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    atIsoRole($ownerA, 'owner', $clinicA->id);

    $typeB = AppointmentType::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'name' => 'Clinic B Muayene',
    ]);

    return [$clinicA, $ownerA, $clinicB, $typeB];
}

// ---------------------------------------------------------------------------
// GET /appointment-types — index read isolation
// ---------------------------------------------------------------------------

it("clinic A's index never exposes clinic B's appointment types", function (): void {
    [$clinicA, $ownerA, $clinicB, $typeB] = twoTypeTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointmentTypes.data', 0));
});

it("clinic A's index response body does not contain clinic B's type name", function (): void {
    [$clinicA, $ownerA, $clinicB, $typeB] = twoTypeTenantFixture();

    $response = $this->actingAs($ownerA)->get(route('appointment-types.index'));

    expect($response->content())->not->toContain($typeB->name);
});

// ---------------------------------------------------------------------------
// GET /appointment-types/{appointmentType}/edit — cross-clinic edit is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on GET /appointment-types/{appointmentType}/edit for a clinic B type', function (): void {
    [$clinicA, $ownerA, $clinicB, $typeB] = twoTypeTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('appointment-types.edit', $typeB))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// PUT /appointment-types/{appointmentType} — cross-clinic update is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on PUT /appointment-types/{appointmentType} for a clinic B type', function (): void {
    [$clinicA, $ownerA, $clinicB, $typeB] = twoTypeTenantFixture();

    $originalName = $typeB->name;

    $this->actingAs($ownerA)
        ->put(route('appointment-types.update', $typeB), [
            'name' => 'Hacked Name',
            'color' => '#FF0000',
            'default_duration_minutes' => 30,
            'is_active' => true,
        ])
        ->assertNotFound();

    // Clinic B's type must be unchanged.
    expect($typeB->fresh()->name)->toBe($originalName);
});

// ---------------------------------------------------------------------------
// DELETE /appointment-types/{appointmentType} — cross-clinic destroy is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on DELETE /appointment-types/{appointmentType} for a clinic B type', function (): void {
    [$clinicA, $ownerA, $clinicB, $typeB] = twoTypeTenantFixture();

    $this->actingAs($ownerA)
        ->delete(route('appointment-types.destroy', $typeB))
        ->assertNotFound();

    // Clinic B's type must still exist (not soft-deleted).
    expect(AppointmentType::withoutGlobalScopes()->find($typeB->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Clinic A's own type is visible and not confused with clinic B's
// ---------------------------------------------------------------------------

it("owner A sees only clinic A's types and not clinic B's when both exist", function (): void {
    [$clinicA, $ownerA, $clinicB, $typeB] = twoTypeTenantFixture();

    $typeA = AppointmentType::factory()->create([
        'clinic_id' => $clinicA->id,
        'vertical_id' => $clinicA->vertical_id,
        'name' => 'Clinic A Muayene',
    ]);

    $this->actingAs($ownerA)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('appointmentTypes.data', 1)
            ->where('appointmentTypes.data.0.id', $typeA->id)
        );
});

// ---------------------------------------------------------------------------
// store — created type is scoped to the active clinic only
// ---------------------------------------------------------------------------

it('a type created by owner A is not visible to owner B', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    atIsoRole($ownerA, 'owner', $clinicA->id);
    atIsoRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)
        ->post(route('appointment-types.store'), [
            'name' => 'Owner A Type',
            'color' => '#0D9488',
            'default_duration_minutes' => 30,
            'is_active' => true,
        ]);

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($ownerB)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointmentTypes.data', 0));
});
