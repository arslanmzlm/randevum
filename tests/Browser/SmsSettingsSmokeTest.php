<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.33 (SMS şablon özelleştirme). Real Chromium via
 * pest-plugin-browser: the SMS settings page must mount, render the two
 * split cards ("Randevu bildirimleri" / "Sistem bildirimleri"), and produce
 * no JS errors.
 *
 * Goes beyond a plain smoke to exercise genuine client-side JS logic: the
 * per-editor live preview is a purely client-computed value (substitutes the
 * allowlisted :clinic/:date/:time tokens from the lang default body using the
 * server-sent sample map, entirely in Vue — no round trip). Asserting the
 * exact substituted preview string proves that computation actually ran.
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

it('renders the SMS settings page with both cards and a computed template preview, with no JS errors', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'Yıldız Diş Kliniği']);
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/clinic/sms-settings')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Page title rendered by PageHeader — in-body, not the shared shell.
        ->assertSee('SMS Bildirimleri')
        // The two split SectionCard titles (UX revision: balance moved to its own card).
        ->assertSee('Randevu bildirimleri')
        ->assertSee('Sistem bildirimleri')
        // A customizable type's label, rendered inside SmsTemplateField.
        ->assertSee('Randevu oluşturuldu')
        // Genuine client-side logic: the live preview computed prop substitutes
        // :clinic/:date/:time from the lang default body using the server-sent
        // sample map — no template set yet, so this proves the client-side
        // token-replacement + default-body fallback both ran correctly.
        ->assertSee('Yıldız Diş Kliniği: 15 Ağustos 2026 14:30 için randevunuz oluşturuldu.')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
