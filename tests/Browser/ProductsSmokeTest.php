<?php

use App\Models\Clinic;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.14 (Product catalog + stock). Real Chromium via
 * pest-plugin-browser: the products index (with a seeded product row and a
 * negative-stock row) and the create page must mount, render page-body content
 * (not just the app shell), and produce no JavaScript errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors()
 * only catches *uncaught* window errors. Vue swallows component setup/render
 * errors and leaves <main> as an empty comment. The assertSee() calls on
 * in-body labels plus the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the products index page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed a regular product and a negative-stock product so both render paths are exercised.
    Product::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Diyabetik Ayak Kremi',
        'is_active' => true,
        'current_stock' => 15,
    ]);

    Product::factory()->negativeStock()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Silikon Topuk Pedi',
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit('/products')
        ->assertNoJavascriptErrors()
        // Page subtitle rendered inside the PageHeader component — lives in the page body.
        ->assertSee('Kliniğinizin ürün ve stok kataloğunu yönetin.')
        // Product name rendered inside the DataTable row.
        ->assertSee('Diyabetik Ayak Kremi')
        // Active status badge rendered in the list.
        ->assertSee('Aktif')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the product create page with form sections and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/products/create')
        ->assertNoJavascriptErrors()
        // Section heading rendered inside the page body.
        ->assertSee('Ürün Bilgileri')
        // Page subtitle rendered by PageHeader.
        ->assertSee('Kataloğa yeni bir ürün ekleyin.')
        // Field label for the stock input — confirms the form rendered.
        ->assertSee('Stok adedi')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
