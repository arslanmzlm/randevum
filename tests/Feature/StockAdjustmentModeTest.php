<?php

use App\Enums\StockMovementReason;
use App\Models\Clinic;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;
use App\Modules\Catalog\Exceptions\StockMovementSignConflictException;
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

function samRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{0: User, 1: Product}
 */
function samOwnerWithProduct(int $stock = 10): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    samRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => $stock,
    ]);

    return [$owner, $product];
}

function samMovementFor(Product $product): ?StockMovement
{
    return StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->first();
}

// ---------------------------------------------------------------------------
// Movement mode — the reason supplies the sign
// ---------------------------------------------------------------------------

it('movement mode applies the reason’s own direction to the quantity', function (string $reason, int $expectedDelta, int $expectedStock): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'movement',
            'reason' => $reason,
            'quantity' => 4,
        ])
        ->assertRedirect(route('products.index'));

    $movement = samMovementFor($product);

    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe($expectedDelta)
        ->and($movement->reason->value)->toBe($reason)
        ->and($movement->balance_after)->toBe($expectedStock)
        ->and($product->fresh()->current_stock)->toBe($expectedStock);
})->with([
    'stock received (in)' => ['stock_in', 4, 14],
    'patient return (in)' => ['patient_return', 4, 14],
    'transfer in' => ['transfer_in', 4, 14],
    'supplier return (out)' => ['supplier_return', -4, 6],
    'wastage (out)' => ['wastage', -4, 6],
    'transfer out' => ['transfer_out', -4, 6],
]);

it('movement mode rejects count correction — it has no direction to derive a sign from', function (): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'movement',
            'reason' => 'count_correction',
            'quantity' => 4,
        ])
        ->assertSessionHasErrors('reason');

    expect(samMovementFor($product))->toBeNull();
});

it('movement mode rejects a negative quantity', function (): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'movement',
            'reason' => 'stock_in',
            'quantity' => -4,
        ])
        ->assertSessionHasErrors('quantity');

    expect(samMovementFor($product))->toBeNull()
        ->and($product->fresh()->current_stock)->toBe(10);
});

it('movement mode rejects a zero quantity', function (): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'movement',
            'reason' => 'wastage',
            'quantity' => 0,
        ])
        ->assertSessionHasErrors('quantity');

    expect(samMovementFor($product))->toBeNull();
});

it('movement mode requires a reason — nothing else supplies the sign', function (): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'movement',
            'quantity' => 4,
        ])
        ->assertSessionHasErrors('reason');
});

it('movement mode may take stock negative', function (): void {
    [$owner, $product] = samOwnerWithProduct(2);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'movement',
            'reason' => 'wastage',
            'quantity' => 5,
        ])
        ->assertRedirect();

    expect($product->fresh()->current_stock)->toBe(-3)
        ->and(samMovementFor($product)->balance_after)->toBe(-3);
});

// ---------------------------------------------------------------------------
// Count mode — the new total supplies the delta
// ---------------------------------------------------------------------------

it('count mode writes the difference between the new total and the current stock', function (int $newTotal, int $expectedDelta): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'count',
            'current_stock' => $newTotal,
            'reason' => 'count_correction',
        ])
        ->assertRedirect();

    $movement = samMovementFor($product);

    expect($movement->quantity)->toBe($expectedDelta)
        ->and($movement->reason)->toBe(StockMovementReason::CountCorrection)
        ->and($movement->balance_after)->toBe($newTotal)
        ->and($product->fresh()->current_stock)->toBe($newTotal);
})->with([
    'counted more than the books say' => [16, 6],
    'counted less than the books say' => [4, -6],
    'counted below zero' => [-2, -12],
]);

it('count mode defaults to the count-correction reason when none is sent', function (): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'count',
            'current_stock' => 12,
        ])
        ->assertRedirect();

    expect(samMovementFor($product)->reason)->toBe(StockMovementReason::CountCorrection);
});

it('count mode rejects a new total that moves against the chosen reason', function (string $reason, int $newTotal): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'count',
            'current_stock' => $newTotal,
            'reason' => $reason,
        ])
        ->assertSessionHasErrors('current_stock');

    expect(samMovementFor($product))->toBeNull()
        ->and($product->fresh()->current_stock)->toBe(10);
})->with([
    // An inbound reason with a total that lowers stock — the bug this design closes.
    'stock received, lower total' => ['stock_in', 4],
    'patient return, lower total' => ['patient_return', 4],
    'transfer in, lower total' => ['transfer_in', 4],
    'stock received, unchanged total' => ['stock_in', 10],
    // An outbound reason with a total that raises stock.
    'supplier return, higher total' => ['supplier_return', 16],
    'wastage, higher total' => ['wastage', 16],
    'transfer out, higher total' => ['transfer_out', 16],
    'wastage, unchanged total' => ['wastage', 10],
]);

it('count mode accepts a new total that agrees with the chosen reason', function (string $reason, int $newTotal, int $expectedDelta): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'count',
            'current_stock' => $newTotal,
            'reason' => $reason,
        ])
        ->assertRedirect();

    expect(samMovementFor($product)->quantity)->toBe($expectedDelta);
})->with([
    'stock received, higher total' => ['stock_in', 18, 8],
    'wastage, lower total' => ['wastage', 3, -7],
]);

// ---------------------------------------------------------------------------
// Mode itself
// ---------------------------------------------------------------------------

it('rejects a request with no mode', function (): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => 20])
        ->assertSessionHasErrors('mode');

    expect(samMovementFor($product))->toBeNull();
});

it('rejects an unknown mode', function (): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'guesswork',
            'current_stock' => 20,
        ])
        ->assertSessionHasErrors('mode');
});

it('movement mode ignores a stray current_stock — the quantity is what counts', function (): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'movement',
            'reason' => 'stock_in',
            'quantity' => 3,
            'current_stock' => 999,
        ])
        ->assertRedirect();

    expect($product->fresh()->current_stock)->toBe(13);
});

// ---------------------------------------------------------------------------
// The funnel's own invariant — the gate does not depend on the form
// ---------------------------------------------------------------------------

it('the stock funnel refuses a delta that contradicts its reason, whatever the caller', function (): void {
    [, $product] = samOwnerWithProduct(10);

    app(ClinicContext::class)->set($product->clinic_id);

    expect(fn () => app(StockMovementService::class)->adjust(
        $product->id,
        -5,
        StockMovementReason::StockIn,
    ))->toThrow(StockMovementSignConflictException::class);

    expect(samMovementFor($product))->toBeNull()
        ->and($product->fresh()->current_stock)->toBe(10);
});

it('the stock funnel accepts a delta that agrees with its reason', function (): void {
    [, $product] = samOwnerWithProduct(10);

    app(ClinicContext::class)->set($product->clinic_id);

    app(StockMovementService::class)->adjust($product->id, -5, StockMovementReason::Wastage);

    expect($product->fresh()->current_stock)->toBe(5);
});

// ---------------------------------------------------------------------------
// Legacy reasons
// ---------------------------------------------------------------------------

it('rows written with the retired reasons still list and still carry their reason', function (string $reason): void {
    [$owner, $product] = samOwnerWithProduct(10);

    StockMovement::factory()->create([
        'clinic_id' => $product->clinic_id,
        'product_id' => $product->id,
        'reason' => StockMovementReason::from($reason),
    ]);

    $this->actingAs($owner)
        ->get(route('products.movements.index', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements.data', 1)
            ->where('movements.data.0.reason', $reason)
        );
})->with(['manual_adjustment', 'return']);

it('the retired reasons can no longer be written through the manual dialog', function (string $reason): void {
    [$owner, $product] = samOwnerWithProduct(10);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'mode' => 'count',
            'current_stock' => 20,
            'reason' => $reason,
        ])
        ->assertSessionHasErrors('reason');

    expect(samMovementFor($product))->toBeNull();
})->with(['manual_adjustment', 'return']);

it('every retired reason still has a label to render in the history list', function (string $reason): void {
    expect(__('stock_movement.reason.'.$reason))->not->toBe('stock_movement.reason.'.$reason);
})->with(['manual_adjustment', 'return']);

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it("clinic A's owner cannot adjust clinic B's stock in either mode", function (string $mode, array $payload): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    samRole($ownerA, 'owner', $clinicA->id);

    $productB = Product::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'current_stock' => 10,
    ]);

    $this->actingAs($ownerA)
        ->patch(route('products.stock.update', $productB), ['mode' => $mode, ...$payload])
        ->assertNotFound();

    expect($productB->fresh()->current_stock)->toBe(10)
        ->and(samMovementFor($productB))->toBeNull();
})->with([
    'movement mode' => ['movement', ['reason' => 'stock_in', 'quantity' => 5]],
    'count mode' => ['count', ['current_stock' => 99, 'reason' => 'count_correction']],
]);
