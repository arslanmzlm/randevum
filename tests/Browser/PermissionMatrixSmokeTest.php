<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.72 (Rol yönetimi arayüzü). Real Chromium via
 * pest-plugin-browser: the editable permission matrix page must mount, render
 * page-body content (not just the app shell), and produce no JS errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component setup/render errors and
 * leaves <main> as an empty comment. The assertSee() calls on in-body labels plus the
 * non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the permission matrix page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/settings/roles')
        ->assertNoJavascriptErrors()
        // Page title: the sidebar renders 'Klinik' as a nav group too, so that string would
        // pass even on a blank page body.
        ->assertSee('Roller ve Yetkiler')
        // Role column header label.
        ->assertSee('Sahip')
        // Guard against silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
