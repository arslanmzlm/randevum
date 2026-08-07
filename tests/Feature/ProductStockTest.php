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
function psTestRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// PATCH /products/{product}/stock — updateStock
// ---------------------------------------------------------------------------

it('PATCH stock updates current_stock on the product', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 10,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => 25])
        ->assertRedirect(route('products.index'));

    expect($product->fresh()->current_stock)->toBe(25);
});

it('current_stock may be set to a negative value', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 5,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => -3])
        ->assertRedirect();

    expect($product->fresh()->current_stock)->toBe(-3);
});

it('current_stock may be set to zero', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 10,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => 0])
        ->assertRedirect();

    expect($product->fresh()->current_stock)->toBe(0);
});

it('stock update only changes current_stock — other fields are untouched', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Unchanged Name',
        'price' => '100.00',
        'current_stock' => 5,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => 99]);

    $fresh = $product->fresh();
    expect($fresh->name)->toBe('Unchanged Name')
        ->and((float) $fresh->price)->toBe(100.0)
        ->and($fresh->current_stock)->toBe(99);
});

it('stock update rejects a missing current_stock with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [])
        ->assertSessionHasErrors('current_stock');
});

it('stock update rejects a non-integer current_stock with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => 'not-a-number'])
        ->assertSessionHasErrors('current_stock');
});

it('stock update flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), ['current_stock' => 50])
        ->assertSessionHas('toasts');
});

it('doctor role gets 403 on PATCH /products/{product}/stock', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    psTestRole($doctorUser, 'doctor', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($doctorUser)
        ->patch(route('products.stock.update', $product), ['current_stock' => 50])
        ->assertForbidden();
});

it('PATCH stock accepts reason and note without breaking the redirect', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 10,
    ]);

    $this->actingAs($owner)
        ->patch(route('products.stock.update', $product), [
            'current_stock' => 20,
            'reason' => 'return',
            'note' => 'Test note',
        ])
        ->assertRedirect(route('products.index'));

    expect($product->fresh()->current_stock)->toBe(20);
});

it('manager is allowed to update stock', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    psTestRole($manager, 'manager', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'current_stock' => 0,
    ]);

    $this->actingAs($manager)
        ->patch(route('products.stock.update', $product), ['current_stock' => 15])
        ->assertRedirect();

    expect($product->fresh()->current_stock)->toBe(15);
});

// ---------------------------------------------------------------------------
// store with initial current_stock
// ---------------------------------------------------------------------------

it('store with an initial current_stock persists it on the product', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), [
            'name' => 'Initial Stock Product',
            'unit' => 'adet',
            'price' => '50.00',
            'current_stock' => 42,
            'is_active' => true,
        ]);

    $product = Product::withoutGlobalScopes()
        ->where('name', 'Initial Stock Product')
        ->first();

    expect($product)->not->toBeNull()
        ->and($product->current_stock)->toBe(42);
});

it('store with a negative initial current_stock persists it on the product', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    psTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), [
            'name' => 'Negative Initial Stock',
            'unit' => 'adet',
            'price' => '50.00',
            'current_stock' => -5,
            'is_active' => true,
        ]);

    $product = Product::withoutGlobalScopes()
        ->where('name', 'Negative Initial Stock')
        ->first();

    expect($product)->not->toBeNull()
        ->and($product->current_stock)->toBe(-5);
});
