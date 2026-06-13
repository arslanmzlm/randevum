<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.60 (Collapsible sidebar / app shell). Real Chromium
 * via pest-plugin-browser: the /dashboard shell must mount with the sidebar
 * visible, toggle must collapse to the rail (client-side localStorage persistence
 * is genuine JS logic), and no JavaScript errors must be raised.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component setup/render errors
 * and renders an empty comment in <main>. The assertSee() calls on in-body
 * labels plus the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the dashboard with sidebar and toggle collapses to rail', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/dashboard')
        ->assertNoJavascriptErrors()
        // In-body content: the page's own heading and placeholder text — these
        // live inside the page component, not the shared shell, so they fail when
        // the page component mounts as an empty comment.
        ->assertSee('Dashboard')
        ->assertSee('Panel içeriği yakında eklenecek.')
        // Guard against the silent-blank-body false green.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // SidebarToggle renders an icon-only button with an aria-label attribute
        // (not visible text). Verify it exists and is in the expanded state.
        ->assertScript(
            '() => !!document.querySelector(\'[aria-label="Menüyü daralt"]\')',
        )
        // Exercise the client-side collapse logic: click the toggle and confirm
        // the sidebar shrinks to the rail (aria-label flips to "Menüyü genişlet").
        ->click('[aria-label="Menüyü daralt"]')
        ->assertScript(
            '() => !!document.querySelector(\'[aria-label="Menüyü genişlet"]\')',
        )
        ->screenshot();
});
