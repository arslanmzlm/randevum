<?php

use App\Enums\StockMovementReason;
use App\Models\Clinic;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
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

it("a movement created via the service carries the product's clinic_id, not the ambient context's", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $productB = Product::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'current_stock' => 5,
    ]);

    // Ambient context is clinic A while the product being adjusted belongs to clinic B.
    app(ClinicContext::class)->set($clinicA->id);

    app(StockMovementService::class)->adjust(
        $productB->id,
        3,
        StockMovementReason::ManualAdjustment,
    );

    $movement = StockMovement::withoutGlobalScopes()->where('product_id', $productB->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->clinic_id)->toBe($clinicB->id);
});
