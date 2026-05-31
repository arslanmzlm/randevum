<?php

use App\Models\Clinic;
use App\Models\Service;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.13 (Service catalog). Real Chromium via
 * pest-plugin-browser: the services index and create pages must mount, render
 * page-body content (not just the app shell), and produce no JavaScript errors.
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

it('renders the services index page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed a service with a known name so the list renders real rows.
    Service::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Podoloji Seansı',
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit('/services')
        ->assertNoJavascriptErrors()
        // Page subtitle is rendered inside the page component's PageHeader —
        // lives in the page body, not in the shared app shell.
        ->assertSee('Kliniğinizin hizmet kataloğunu yönetin.')
        // Service name rendered inside the DataTable row.
        ->assertSee('Podoloji Seansı')
        // Active status badge rendered in the list.
        ->assertSee('Aktif')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the service create page with form sections and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/services/create')
        ->assertNoJavascriptErrors()
        // Section heading for the info card — rendered in the page body.
        ->assertSee('Hizmet Bilgileri')
        // Section heading for the clinical templates card.
        ->assertSee('Varsayılan Klinik Metinleri')
        // Page subtitle rendered by the PageHeader component.
        ->assertSee('Kataloğa yeni bir hizmet ekleyin.')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
