<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.27 (Toplu randevu iptal / Günü Kapat). Real Chromium via
 * pest-plugin-browser: the /appointments/bulk-cancel page must mount, render page-body
 * content (not just the app shell), and produce no JavaScript errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only catches
 * *uncaught* window errors. Vue swallows component setup/render errors and leaves <main>
 * as an empty comment. The assertSee() calls on in-body labels plus the non-empty <main>
 * script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the bulk-cancel page with form fields and preview area and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams) — owner has appointments.bulkCancel.
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/appointments/bulk-cancel')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Preview section heading — rendered in the right panel of the page body, never in the app shell.
        ->assertSee('İptal edilecek randevular')
        // Idle-state hint — rendered inside the preview panel when no date range is chosen.
        ->assertSee('Önizleme için bir tarih aralığı seçin.')
        // Block-new-bookings toggle label — rendered inside the criteria form, not in the nav.
        ->assertSee('Günü yeni rezervasyona da kapat')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
