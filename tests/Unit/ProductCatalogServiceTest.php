<?php

use App\Models\Clinic;
use App\Models\Product;
use App\Modules\Catalog\Services\ProductCatalogService;
use App\Support\ClinicContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app(ClinicContext::class)->forget();
});

it('create sets vertical_id from the active clinic', function (): void {
    $clinic = Clinic::factory()->create();

    app(ClinicContext::class)->set($clinic->id);

    $service = app(ProductCatalogService::class);

    $product = $service->create([
        'name' => 'Service Layer Product',
        'unit' => 'adet',
        'price' => '80.00',
        'is_active' => true,
    ]);

    expect($product->vertical_id)->toBe($clinic->vertical_id);
});

it('create does not allow a client-supplied vertical_id to override the clinic vertical', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    app(ClinicContext::class)->set($clinicA->id);

    $service = app(ProductCatalogService::class);

    $product = $service->create([
        'name' => 'Override Attempt',
        'unit' => 'adet',
        'price' => '50.00',
        // Client tries to supply clinicB's vertical — service must overwrite with clinicA's.
        'vertical_id' => $clinicB->vertical_id,
        'is_active' => true,
    ]);

    expect($product->vertical_id)->toBe($clinicA->vertical_id);
});

it('updateStock sets only current_stock and leaves other fields unchanged', function (): void {
    $clinic = Clinic::factory()->create();

    app(ClinicContext::class)->set($clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Unchanged Name',
        'price' => '100.00',
        'current_stock' => 5,
    ]);

    $service = app(ProductCatalogService::class);
    $updated = $service->updateStock($product, -10);

    expect($updated->current_stock)->toBe(-10)
        ->and($updated->name)->toBe('Unchanged Name')
        ->and((float) $updated->price)->toBe(100.0);
});

it('delete soft-deletes the product', function (): void {
    $clinic = Clinic::factory()->create();

    app(ClinicContext::class)->set($clinic->id);

    $product = Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $service = app(ProductCatalogService::class);
    $service->delete($product);

    expect(Product::withoutGlobalScopes()->find($product->id)->deleted_at)->not->toBeNull();
});
