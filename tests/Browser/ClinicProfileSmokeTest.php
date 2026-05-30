<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.19 (Clinic profile + working hours). Real Chromium
 * via pest-plugin-browser: the page must mount, render page-body content (not
 * just the shell), and have no JavaScript errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component setup/render errors
 * and renders an empty comment in <main>. The assertSee() calls on in-body
 * labels plus the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the clinic profile page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/clinic')
        ->assertNoJavascriptErrors()
        // In-body section headers — these live INSIDE the page component,
        // not in the shared app shell, so they fail on a blank mount.
        ->assertSee('Klinik Bilgileri')
        ->assertSee('Çalışma Saatleri')
        ->assertSee('Görseller')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
