<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.40a (SMS gönderim gate + klinik SMS tercihleri).
 * Real Chromium via pest-plugin-browser: the SMS settings page must mount,
 * render page-body content (not just the app shell), and produce no JS errors.
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

it('renders the SMS settings page with toggle rows and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/clinic/sms-settings')
        ->assertNoJavascriptErrors()
        // Page title rendered by PageHeader — in-body, not the shared shell.
        ->assertSee('SMS Bildirimleri')
        // Section heading rendered inside the settings card body.
        ->assertSee('Bildirim türleri')
        // A toggle label for one of the 6 clinic-scoped SMS types.
        ->assertSee('Randevu oluşturuldu')
        // Guard against silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
