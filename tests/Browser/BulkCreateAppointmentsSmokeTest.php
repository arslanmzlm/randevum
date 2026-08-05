<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.59 (Toplu randevu oluşturma). Real Chromium via
 * pest-plugin-browser: the appointments/bulk-create page must mount, render
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

it('renders the bulk appointment create page with the occurrence generator and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/appointments/bulk-create')
        ->assertNoJavascriptErrors()
        // Generator card heading inside the page body — not in the shared app shell.
        ->assertSee('Randevu Üreteci')
        // Generator field label, always rendered inside the generator card.
        ->assertSee('Randevu sayısı')
        // Count + interval sit in the details card; the free-day field appears with "Özel (gün)".
        ->assertSee('Aralık')
        // Submit button label rendered inside the page body.
        ->assertSee('Randevuları Oluştur')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
