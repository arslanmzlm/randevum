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

it('opens the service create dialog from the list with no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    // ?new=1 is the shareable form of the dialog — the same state the "Hizmet Ekle" button sets.
    visit('/services?new=1')
        ->assertNoJavascriptErrors()
        // Dialog header — rendered by CrudDialog, not the list page.
        ->assertSee('Hizmet Ekle')
        // Section heading for the clinical templates block inside the dialog.
        ->assertSee('Varsayılan Klinik Metinleri')
        // Field label, so the entity's field partial is proven to render.
        ->assertSee('Süre (dk)')
        // Guard against the silent-blank-body false green: the dialog must carry content.
        ->assertScript(
            '() => (document.querySelector(".p-dialog")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
