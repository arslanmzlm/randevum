<?php

use App\Enums\StockMovementReason;
use App\Models\Clinic;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Catalog\Exceptions\ProductClinicMismatchException;
use App\Modules\Catalog\Services\StockMovementService;
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
function smIsoRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it("clinic A owner gets 404 on GET clinic B's product movements", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    smIsoRole($ownerA, 'owner', $clinicA->id);

    $productB = Product::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
    ]);

    $this->actingAs($ownerA)
        ->get(route('products.movements.index', $productB))
        ->assertNotFound();
});

it("movements written for clinic A are absent from clinic B's list even for a same-named product", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerB = User::factory()->create();
    smIsoRole($ownerB, 'owner', $clinicB->id);

    $productA = Product::factory()->create([
        'clinic_id' => $clinicA->id,
        'vertical_id' => $clinicA->vertical_id,
        'name' => 'Shared Product Name',
    ]);
    $productB = Product::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'name' => 'Shared Product Name',
    ]);

    StockMovement::factory()->create(['clinic_id' => $clinicA->id, 'product_id' => $productA->id]);
    StockMovement::factory()->create(['clinic_id' => $clinicB->id, 'product_id' => $productB->id]);

    $this->actingAs($ownerB)
        ->get(route('products.movements.index', $productB))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('movements.data', 1));
});

it("a movement created via the service carries the product's own clinic_id when no ambient context is bound", function (): void {
    $clinicB = Clinic::factory()->create();

    $productB = Product::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'current_stock' => 5,
    ]);

    // No active clinic (the queue/command shape) — the caller's productId is the source
    // of truth, and the movement must be stamped from the locked product, never from an
    // ambient context that doesn't even exist here.
    app(ClinicContext::class)->forget();

    app(StockMovementService::class)->adjust(
        $productB->id,
        3,
        StockMovementReason::ManualAdjustment,
    );

    $movement = StockMovement::withoutGlobalScopes()->where('product_id', $productB->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->clinic_id)->toBe($clinicB->id);
});

it('adjusting a product that belongs to another clinic than the active one throws a distinct isolation exception, not a generic not-found', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $productB = Product::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'current_stock' => 5,
    ]);

    // Ambient context is clinic A while the product being adjusted belongs to clinic B —
    // a tenant-isolation violation, distinguishable from a plain missing product id.
    app(ClinicContext::class)->set($clinicA->id);

    expect(fn () => app(StockMovementService::class)->adjust(
        $productB->id,
        3,
        StockMovementReason::ManualAdjustment,
    ))->toThrow(ProductClinicMismatchException::class);

    expect(StockMovement::withoutGlobalScopes()->where('product_id', $productB->id)->exists())->toBeFalse();
});

it('adjusting a product id that does not exist at all throws a plain not-found error, distinct from a clinic mismatch', function (): void {
    app(ClinicContext::class)->set(Clinic::factory()->create()->id);

    expect(fn () => app(StockMovementService::class)->adjust(
        PHP_INT_MAX,
        1,
        StockMovementReason::ManualAdjustment,
    ))->toThrow(RuntimeException::class, 'not found for stock adjustment');
});
