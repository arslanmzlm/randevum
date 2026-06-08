<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.4 (Takvim). Real Chromium via pest-plugin-browser:
 * the /calendar page must mount, render page-body content (not just the app shell),
 * and produce no JavaScript errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component render errors and leaves
 * <main> as an empty comment. The assertSee() calls on in-body labels plus the
 * non-empty <main> script assert are the real guards.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the calendar page with view controls and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/calendar')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Page subtitle rendered inside PageHeader — unique to this page, not in nav.
        ->assertSee('Randevuları ay, hafta ve gün görünümünde inceleyin.')
        // "Bugün" button in the calendar controls bar — not part of the shared shell.
        ->assertSee('Bugün')
        // View-switch options rendered by SelectButton inside the controls bar.
        ->assertSee('Hafta')
        ->assertSee('Ay')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
