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
function pcTestRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a valid POST /products payload.
 *
 * @return array<string, mixed>
 */
function pcStorePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Silikon Ped',
        'description' => 'Yüksek kaliteli silikon topuk pedi.',
        'brand' => 'OrthoFit',
        'category' => 'Ortopedik',
        'sku' => 'SKU-0001',
        'unit' => 'adet',
        'price' => '150.00',
        'current_stock' => null,
        'is_active' => true,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /products — access control + rendering
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /products', function (): void {
    $this->get(route('products.index'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /products and the Index component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Index')
            ->has('products')
            ->has('auth.permissions')
        );
});

it('index canManage is true for owner and false for doctor role', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);
    pcTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($owner)
        ->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('products.create')));

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($doctorUser)
        ->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('products.create')));
});

it('manager can access GET /products and canManage is true', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    pcTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('products.create')));
});

it('index lists only the active clinic\'s products', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinicA->id);

    Product::factory()->create(['clinic_id' => $clinicA->id, 'vertical_id' => $clinicA->vertical_id, 'name' => 'Product Alpha']);
    Product::factory()->create(['clinic_id' => $clinicB->id, 'vertical_id' => $clinicB->vertical_id, 'name' => 'Product Beta']);

    $this->actingAs($owner)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Product Alpha')
        );
});

it('doctor role can access GET /products (read-only)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    pcTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('products/Index'));
});

it('index passes currency from the active clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('products.index'))
        ->assertInertia(fn ($page) => $page->has('currency'));
});

// ---------------------------------------------------------------------------
// GET /products/create — access control + rendering
// ---------------------------------------------------------------------------

it('owner can access GET /products/create and the Create component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('products.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('products/Create'));
});

it('manager can access GET /products/create', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    pcTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('products.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('products/Create'));
});

it('create page passes distinct, sorted brand/category suggestions scoped to the active clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $other = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'brand' => 'Hartmann', 'category' => 'Pansuman']);
    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'brand' => 'Convatec', 'category' => 'Pansuman']);
    // Another clinic's values must never leak into the suggestions.
    Product::factory()->create(['clinic_id' => $other->id, 'vertical_id' => $other->vertical_id, 'brand' => 'LeakBrand', 'category' => 'LeakCategory']);

    $this->actingAs($owner)
        ->get(route('products.create'))
        ->assertInertia(fn ($page) => $page
            ->where('brands', ['Convatec', 'Hartmann'])
            ->where('categories', ['Pansuman'])
            ->etc()
        );
});

it('doctor role gets 403 on GET /products/create', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    pcTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('products.create'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// POST /products — store
// ---------------------------------------------------------------------------

it('owner can create a product and is redirected to the index', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload())
        ->assertRedirect(route('products.index'));
});

it('store auto-sets clinic_id from ClinicContext', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['name' => 'Clinic ID Check']));

    $product = Product::withoutGlobalScopes()
        ->where('name', 'Clinic ID Check')
        ->first();

    expect($product)->not->toBeNull()
        ->and($product->clinic_id)->toBe($clinic->id);
});

it('store auto-sets vertical_id from the active clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['name' => 'Vertical ID Check']));

    $product = Product::withoutGlobalScopes()
        ->where('name', 'Vertical ID Check')
        ->first();

    expect($product)->not->toBeNull()
        ->and($product->vertical_id)->toBe($clinic->vertical_id);
});

it('price is persisted as a decimal value', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['name' => 'Decimal Test', 'price' => '299.99']));

    $product = Product::withoutGlobalScopes()
        ->where('name', 'Decimal Test')
        ->first();

    expect($product)->not->toBeNull()
        ->and((float) $product->price)->toBe(299.99);
});

it('store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload())
        ->assertSessionHas('toasts');
});

it('doctor role gets 403 on POST /products', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    pcTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('products.store'), pcStorePayload())
        ->assertForbidden();
});

it('manager can create a product', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    pcTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('products.store'), pcStorePayload(['name' => 'Manager Product']))
        ->assertRedirect(route('products.index'));

    expect(Product::withoutGlobalScopes()->where('name', 'Manager Product')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Validation — store
// ---------------------------------------------------------------------------

it('store rejects a missing name with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

it('store rejects a negative price with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['price' => '-10']))
        ->assertSessionHasErrors('price');
});

it('store rejects a missing price with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['price' => null]))
        ->assertSessionHasErrors('price');
});

it('store rejects a name longer than 255 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['name' => str_repeat('a', 256)]))
        ->assertSessionHasErrors('name');
});

it('store rejects a description longer than 2000 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['description' => str_repeat('x', 2001)]))
        ->assertSessionHasErrors('description');
});

it('store rejects a brand longer than 100 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['brand' => str_repeat('b', 101)]))
        ->assertSessionHasErrors('brand');
});

it('store rejects a category longer than 100 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['category' => str_repeat('c', 101)]))
        ->assertSessionHasErrors('category');
});

it('store rejects a sku longer than 100 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['sku' => str_repeat('s', 101)]))
        ->assertSessionHasErrors('sku');
});

it('store rejects a missing unit with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('products.store'), pcStorePayload(['unit' => '']))
        ->assertSessionHasErrors('unit');
});

// ---------------------------------------------------------------------------
// GET /products/{product}/edit — access control + rendering
// ---------------------------------------------------------------------------

it('owner can access the edit page and the Edit component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('products.edit', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('products/Edit')
            ->where('product.id', $product->id)
        );
});

it('manager can access the edit page', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    pcTestRole($manager, 'manager', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($manager)
        ->get(route('products.edit', $product))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('products/Edit'));
});

it('doctor role gets 403 on GET /products/{product}/edit', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    pcTestRole($doctorUser, 'doctor', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($doctorUser)
        ->get(route('products.edit', $product))
        ->assertForbidden();
});

it('edit props expose the product resource shape', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Tırnak Makası',
        'price' => '75.50',
        'current_stock' => 10,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('products.edit', $product))
        ->assertInertia(fn ($page) => $page
            ->where('product.name', 'Tırnak Makası')
            ->where('product.current_stock', 10)
            ->where('product.is_active', true)
        );
});

// ---------------------------------------------------------------------------
// PUT /products/{product} — update
// ---------------------------------------------------------------------------

it('owner can update a product', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Old Name',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->put(route('products.update', $product), pcStorePayload([
            'name' => 'New Name',
            'is_active' => false,
        ]))
        ->assertRedirect(route('products.index'));

    $fresh = $product->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->is_active)->toBeFalse();
});

it('manager can update a product', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    pcTestRole($manager, 'manager', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Manager Old Name',
    ]);

    $this->actingAs($manager)
        ->put(route('products.update', $product), pcStorePayload(['name' => 'Manager New Name']))
        ->assertRedirect();

    expect($product->fresh()->name)->toBe('Manager New Name');
});

it('update flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->put(route('products.update', $product), pcStorePayload())
        ->assertSessionHas('toasts');
});

it('doctor role gets 403 on PUT /products/{product}', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    pcTestRole($doctorUser, 'doctor', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($doctorUser)
        ->put(route('products.update', $product), pcStorePayload())
        ->assertForbidden();
});

it('update rejects a missing name with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->put(route('products.update', $product), pcStorePayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

it('update preserves price when edited', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'price' => '100.00',
    ]);

    $this->actingAs($owner)
        ->put(route('products.update', $product), pcStorePayload(['price' => '999.99']));

    expect((float) $product->fresh()->price)->toBe(999.99);
});

// ---------------------------------------------------------------------------
// DELETE /products/{product} — soft delete
// ---------------------------------------------------------------------------

it('owner can soft-delete a product via DELETE /products/{product}', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->delete(route('products.destroy', $product))
        ->assertRedirect(route('products.index'));

    expect(Product::withoutGlobalScopes()->find($product->id)->deleted_at)->not->toBeNull();
});

it('soft delete leaves the row in the database', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->delete(route('products.destroy', $product));

    expect(Product::withoutGlobalScopes()->find($product->id))->not->toBeNull();
});

it('manager can soft-delete a product', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    pcTestRole($manager, 'manager', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($manager)
        ->delete(route('products.destroy', $product))
        ->assertRedirect();

    expect(Product::withoutGlobalScopes()->find($product->id)->deleted_at)->not->toBeNull();
});

it('doctor role gets 403 on DELETE /products/{product}', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    pcTestRole($doctorUser, 'doctor', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($doctorUser)
        ->delete(route('products.destroy', $product))
        ->assertForbidden();
});

it('DELETE /products/{product} redirects with a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->delete(route('products.destroy', $product))
        ->assertRedirect(route('products.index'))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// GET /products — server-side paginator shape, search, sort, is_active filter
// ---------------------------------------------------------------------------

it('index returns a paginated shape with data and meta', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    Product::factory()->count(3)->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('products.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 3)
            ->has('products.meta')
            ->where('products.meta.total', 3)
        );
});

it('index echoes the filters prop', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('products.index', ['filter' => ['search' => 'test'], 'per_page' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('query.filter.search', 'test')
            ->where('query.per_page', 10)
        );
});

it('index filters products by search on name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Silikon Ped']);
    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Pansuman Seti']);

    $this->actingAs($owner)
        ->get(route('products.index', ['filter' => ['search' => 'Silikon']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('products.data', 1)
            ->where('products.data.0.name', 'Silikon Ped')
        );
});

it('index filters products by search on sku', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Ürün A', 'sku' => 'SKU-FIND-ME']);
    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Ürün B', 'sku' => 'SKU-OTHER']);

    $this->actingAs($owner)
        ->get(route('products.index', ['filter' => ['search' => 'FIND-ME']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('index filters products by is_active = true', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'is_active' => true]);
    Product::factory()->inactive()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('products.index', ['filter' => ['is_active' => '1']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('index filters products by is_active = false', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'is_active' => true]);
    Product::factory()->inactive()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('products.index', ['filter' => ['is_active' => '0']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('products.data', 1));
});

it('index sorts products by price ascending when sort_order is 1', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Expensive', 'price' => '500.00']);
    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Cheap', 'price' => '50.00']);

    $this->actingAs($owner)
        ->get(route('products.index', ['sort' => 'price']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('products.data.0.name', 'Cheap')
            ->where('products.data.1.name', 'Expensive')
        );
});

// ---------------------------------------------------------------------------
// Factory states
// ---------------------------------------------------------------------------

it('inactive factory state sets is_active to false', function (): void {
    $product = Product::factory()->inactive()->make();

    expect($product->is_active)->toBeFalse();
});

it('inactive product is excluded from the active scope', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pcTestRole($owner, 'owner', $clinic->id);

    Product::factory()->inactive()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);
    Product::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $activeCount = Product::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->active()
        ->count();

    expect($activeCount)->toBe(1);
});

it('negativeStock factory state sets current_stock below zero', function (): void {
    $product = Product::factory()->negativeStock()->make();

    expect($product->current_stock)->toBeLessThan(0);
});
