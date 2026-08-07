<?php

use App\Enums\StockMovementReason;
use App\Models\Clinic;
use App\Models\Product;
use App\Models\StockMovement;
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
function smTestRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// POST /products — initial movement
// ---------------------------------------------------------------------------

it('store with current_stock = 42 writes one initial movement with balance_after 42', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), [
            'name' => 'Initial Stock Product',
            'unit' => 'adet',
            'price' => '50.00',
            'current_stock' => 42,
            'is_active' => true,
        ]);

    $product = Product::withoutGlobalScopes()->where('name', 'Initial Stock Product')->first();

    $movement = StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(42)
        ->and($movement->balance_after)->toBe(42)
        ->and($movement->reason)->toBe(StockMovementReason::Initial)
        ->and($movement->created_by)->toBe($owner->id);
});

it('store with current_stock null writes no movement', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), [
            'name' => 'No Stock Product',
            'unit' => 'adet',
            'price' => '50.00',
            'current_stock' => null,
            'is_active' => true,
        ]);

    $product = Product::withoutGlobalScopes()->where('name', 'No Stock Product')->first();

    expect(StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('store with current_stock 0 writes no movement', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), [
            'name' => 'Zero Stock Product',
            'unit' => 'adet',
            'price' => '50.00',
            'current_stock' => 0,
            'is_active' => true,
        ]);

    $product = Product::withoutGlobalScopes()->where('name', 'Zero Stock Product')->first();

    expect(StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// PATCH /products/{product}/stock — manual movement
// ---------------------------------------------------------------------------

it('PATCH stock 10 to 25 writes one manual_adjustment movement of +15', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 10,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => 25]);

    $movement = StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->first();

    expect($movement)->not->toBeNull()
        ->and($movement->quantity)->toBe(15)
        ->and($movement->balance_after)->toBe(25)
        ->and($movement->reason)->toBe(StockMovementReason::ManualAdjustment)
        ->and($movement->created_by)->toBe($owner->id);
});

it('PATCH stock with reason=return and a note persists them verbatim', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 10,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'current_stock' => 15,
            'reason' => 'return',
            'note' => 'Hasta iade etti',
        ]);

    $movement = StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->first();

    expect($movement->reason)->toBe(StockMovementReason::Return)
        ->and($movement->note)->toBe('Hasta iade etti');
});

it('PATCH stock with the same value writes no movement but still redirects with a toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 10,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => 10])
        ->assertRedirect(route('products.index'))
        ->assertSessionHas('toasts');

    expect(StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->exists())->toBeFalse();
});

it('PATCH stock to a negative value writes a movement with a negative balance_after', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 5,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => -3]);

    $movement = StockMovement::withoutGlobalScopes()->where('product_id', $product->id)->first();

    expect($movement->quantity)->toBe(-8)
        ->and($movement->balance_after)->toBe(-3);
});

it('PATCH stock with an invalid reason returns a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'current_stock' => 10,
            'reason' => 'treatment_usage',
        ])
        ->assertSessionHasErrors('reason');
});

// ---------------------------------------------------------------------------
// GET /products/{product}/movements
// ---------------------------------------------------------------------------

it('owner can view the movements page and gets the prop shape, newest-first', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $older = StockMovement::factory()->create([
        'clinic_id' => $clinic->id,
        'product_id' => $product->id,
        'created_at' => now()->subDay(),
    ]);
    $newer = StockMovement::factory()->create([
        'clinic_id' => $clinic->id,
        'product_id' => $product->id,
        'created_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('products.movements.index', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Movements')
            ->has('product')
            ->has('movements.data', 2)
            ->where('movements.data.0.id', $newer->id)
            ->where('movements.data.1.id', $older->id)
        );
});

it('doctor role (has products.viewAny, not products.manageStock) can view movements, but the manual stock-adjust endpoint still 403s', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    smTestRole($doctorUser, 'doctor', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($doctorUser)
        ->get(route('products.movements.index', $product))
        ->assertOk();

    $this->actingAs($doctorUser)
        ->patch(route('products.stock.update', $product), ['current_stock' => 99])
        ->assertForbidden();
});

it('a role with neither products.viewAny nor products.manageStock gets 403 on GET /products/{product}/movements', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    smTestRole($assistant, 'assistant', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($assistant)
        ->get(route('products.movements.index', $product))
        ->assertForbidden();
});

it('manager can view the movements page', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    smTestRole($manager, 'manager', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($manager)
        ->get(route('products.movements.index', $product))
        ->assertOk();
});

it('reason filter narrows the movements list', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    StockMovement::factory()->initial()->create(['clinic_id' => $clinic->id, 'product_id' => $product->id]);
    StockMovement::factory()->create([
        'clinic_id' => $clinic->id,
        'product_id' => $product->id,
        'reason' => StockMovementReason::ManualAdjustment,
    ]);

    $this->actingAs($owner)
        ->get(route('products.movements.index', ['product' => $product->id, 'filter' => ['reason' => 'initial']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements.data', 1)
            ->where('movements.data.0.reason', 'initial')
        );
});

it('date-range filter narrows the movements list', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    StockMovement::factory()->create([
        'clinic_id' => $clinic->id,
        'product_id' => $product->id,
        'created_at' => now()->subDays(10),
    ]);
    $recent = StockMovement::factory()->create([
        'clinic_id' => $clinic->id,
        'product_id' => $product->id,
        'created_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('products.movements.index', [
            'product' => $product->id,
            'filter' => ['start_date' => now()->format('Y-m-d')],
        ]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('movements.data', 1)
            ->where('movements.data.0.id', $recent->id)
        );
});
