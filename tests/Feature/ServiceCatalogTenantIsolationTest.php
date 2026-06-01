<?php

use App\Models\Clinic;
use App\Models\Service;
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
function scIsoRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Two-tenant fixture: returns [$clinicA, $ownerA, $clinicB, $serviceFromB].
 *
 * Owner A belongs to clinic A; the service belongs to clinic B.
 *
 * @return array{0: Clinic, 1: User, 2: Clinic, 3: Service}
 */
function twoServiceTenantFixture(): array
{
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    scIsoRole($ownerA, 'owner', $clinicA->id);

    $serviceB = Service::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'name' => 'Clinic B Service',
    ]);

    return [$clinicA, $ownerA, $clinicB, $serviceB];
}

// ---------------------------------------------------------------------------
// GET /services — index read isolation
// ---------------------------------------------------------------------------

it("clinic A's index never exposes clinic B's services", function (): void {
    [$clinicA, $ownerA, $clinicB, $serviceB] = twoServiceTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('services.data', 0));
});

it("clinic A's index response body does not contain clinic B's service name", function (): void {
    [$clinicA, $ownerA, $clinicB, $serviceB] = twoServiceTenantFixture();

    $response = $this->actingAs($ownerA)->get(route('services.index'));

    expect($response->content())->not->toContain($serviceB->name);
});

// ---------------------------------------------------------------------------
// GET /services/{service}/edit — cross-clinic edit is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on GET /services/{service}/edit for a clinic B service', function (): void {
    [$clinicA, $ownerA, $clinicB, $serviceB] = twoServiceTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('services.edit', $serviceB))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// PUT /services/{service} — cross-clinic update is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on PUT /services/{service} for a clinic B service', function (): void {
    [$clinicA, $ownerA, $clinicB, $serviceB] = twoServiceTenantFixture();

    $originalName = $serviceB->name;

    $this->actingAs($ownerA)
        ->put(route('services.update', $serviceB), [
            'name' => 'Hacked Name',
            'price' => '9999.00',
            'is_active' => true,
        ])
        ->assertNotFound();

    // Clinic B's service must be unchanged
    expect($serviceB->fresh()->name)->toBe($originalName);
});

// ---------------------------------------------------------------------------
// DELETE /services/{service} — cross-clinic destroy is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on DELETE /services/{service} for a clinic B service', function (): void {
    [$clinicA, $ownerA, $clinicB, $serviceB] = twoServiceTenantFixture();

    $this->actingAs($ownerA)
        ->delete(route('services.destroy', $serviceB))
        ->assertNotFound();

    // Clinic B's service must still exist (not soft-deleted)
    expect(Service::withoutGlobalScopes()->find($serviceB->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Clinic A's own service is visible and not confused with clinic B's
// ---------------------------------------------------------------------------

it("owner A sees only clinic A's services and not clinic B's when both exist", function (): void {
    [$clinicA, $ownerA, $clinicB, $serviceB] = twoServiceTenantFixture();

    $serviceA = Service::factory()->create([
        'clinic_id' => $clinicA->id,
        'vertical_id' => $clinicA->vertical_id,
        'name' => 'Clinic A Service',
    ]);

    $this->actingAs($ownerA)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.id', $serviceA->id)
        );
});

// ---------------------------------------------------------------------------
// store — created service is scoped to the active clinic only
// ---------------------------------------------------------------------------

it('a service created by owner A is not visible to owner B', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    scIsoRole($ownerA, 'owner', $clinicA->id);
    scIsoRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)
        ->post(route('services.store'), [
            'name' => 'Owner A Service',
            'price' => '200.00',
            'is_active' => true,
        ]);

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($ownerB)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('services.data', 0));
});
