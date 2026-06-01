<?php

use App\Models\Clinic;
use App\Models\Product;
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
function pciIsoRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Two-tenant fixture: returns [$clinicA, $ownerA, $clinicB, $productFromB].
 *
 * Owner A belongs to clinic A; the product belongs to clinic B.
 *
 * @return array{0: Clinic, 1: User, 2: Clinic, 3: Product}
 */
function twoProductTenantFixture(): array
{
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    pciIsoRole($ownerA, 'owner', $clinicA->id);

    $productB = Product::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'name' => 'Clinic B Product',
        'current_stock' => 20,
    ]);

    return [$clinicA, $ownerA, $clinicB, $productB];
}

// ---------------------------------------------------------------------------
// GET /products — index read isolation
// ---------------------------------------------------------------------------

it("clinic A's index never exposes clinic B's products", function (): void {
    [$clinicA, $ownerA, $clinicB, $productB] = twoProductTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 0));
});

it("clinic A's index response body does not contain clinic B's product name", function (): void {
    [$clinicA, $ownerA, $clinicB, $productB] = twoProductTenantFixture();

    $response = $this->actingAs($ownerA)->get(route('products.index'));

    expect($response->content())->not->toContain($productB->name);
});

// ---------------------------------------------------------------------------
// GET /products/{product}/edit — cross-clinic edit is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on GET /products/{product}/edit for a clinic B product', function (): void {
    [$clinicA, $ownerA, $clinicB, $productB] = twoProductTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('products.edit', $productB))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// PUT /products/{product} — cross-clinic update is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on PUT /products/{product} for a clinic B product', function (): void {
    [$clinicA, $ownerA, $clinicB, $productB] = twoProductTenantFixture();

    $originalName = $productB->name;

    $this->actingAs($ownerA)
        ->put(route('products.update', $productB), [
            'name' => 'Hacked Name',
            'price' => '9999.00',
            'unit' => 'adet',
            'is_active' => true,
        ])
        ->assertNotFound();

    // Clinic B's product must be unchanged
    expect($productB->fresh()->name)->toBe($originalName);
});

// ---------------------------------------------------------------------------
// PATCH /products/{product}/stock — cross-clinic stock update is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on PATCH /products/{product}/stock for a clinic B product', function (): void {
    [$clinicA, $ownerA, $clinicB, $productB] = twoProductTenantFixture();

    $originalStock = $productB->current_stock;

    $this->actingAs($ownerA)
        ->patch(route('products.stock.update', $productB), ['current_stock' => 999])
        ->assertNotFound();

    // Clinic B's stock must be unchanged
    expect($productB->fresh()->current_stock)->toBe($originalStock);
});

// ---------------------------------------------------------------------------
// DELETE /products/{product} — cross-clinic destroy is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on DELETE /products/{product} for a clinic B product', function (): void {
    [$clinicA, $ownerA, $clinicB, $productB] = twoProductTenantFixture();

    $this->actingAs($ownerA)
        ->delete(route('products.destroy', $productB))
        ->assertNotFound();

    // Clinic B's product must still exist (not soft-deleted)
    expect(Product::withoutGlobalScopes()->find($productB->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Clinic A's own product is visible and not confused with clinic B's
// ---------------------------------------------------------------------------

it("owner A sees only clinic A's products and not clinic B's when both exist", function (): void {
    [$clinicA, $ownerA, $clinicB, $productB] = twoProductTenantFixture();

    $productA = Product::factory()->create([
        'clinic_id' => $clinicA->id,
        'vertical_id' => $clinicA->vertical_id,
        'name' => 'Clinic A Product',
    ]);

    $this->actingAs($ownerA)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.id', $productA->id)
        );
});

// ---------------------------------------------------------------------------
// store — created product is scoped to the active clinic only
// ---------------------------------------------------------------------------

it('a product created by owner A is not visible to owner B', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    pciIsoRole($ownerA, 'owner', $clinicA->id);
    pciIsoRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)
        ->post(route('products.store'), [
            'name' => 'Owner A Product',
            'unit' => 'adet',
            'price' => '200.00',
            'is_active' => true,
        ]);

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($ownerB)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 0));
});
