<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.9 (Hasta arama). Real Chromium via
 * pest-plugin-browser: the PatientSearchSelect quick-find on the patients Index
 * must mount, render its input, return suggestions on keystrokes, and produce no
 * JavaScript errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component setup/render errors
 * and leaves <main> as an empty comment. The assertSee() calls on in-body labels
 * plus the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the patient quick-find input and returns suggestions on search', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed a patient with a name unique enough to find via the autocomplete.
    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ahmet',
        'last_name' => 'Yılmaz',
    ]);

    $this->actingAs($owner);

    visit('/patients')
        ->assertNoJavascriptErrors()
        // Page subtitle lives inside the page component (PageHeader), not the app shell.
        ->assertSee('Kliniğinizin hasta kayıtlarını yönetin.')
        // "Hasta Ekle" button is rendered inside the page header actions — page-body content.
        ->assertSee('Hasta Ekle')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // The quick-find AutoComplete input must be present with its placeholder.
        ->assertScript(
            '() => !!document.querySelector("input[placeholder*=\'Hasta ara\']")',
        )
        // typeSlowly simulates real keystrokes (keydown/keypress/keyup per char) so
        // PrimeVue AutoComplete's @complete handler fires correctly. Each character is
        // typed 150 ms apart (totalling ~600 ms), which is longer than the 300 ms
        // debounce — the search fires mid-way and the panel should be open before we assert.
        ->typeSlowly('[placeholder*="Hasta ara"]', 'Ahm', 150)
        // Wait for the /patients/search XHR to settle and Vue to update the suggestion list.
        ->waitForEvent('networkidle')
        // Autocomplete suggestion items use role="option"; DataTable rows use role="row".
        // This check confirms the dropdown panel actually rendered with the patient's name,
        // not just that the DataTable happens to show the same row.
        ->assertScript(
            '() => Array.from(document.querySelectorAll("[role=\"option\"]")).some(el => el.textContent.includes("Ahmet Yılmaz"))',
        )
        // The patient's full_name is also visible on the page (in the dropdown).
        ->assertSee('Ahmet Yılmaz')
        ->assertNoJavascriptErrors()
        ->screenshot();
});
