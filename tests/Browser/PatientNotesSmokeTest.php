<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.12 (Hasta-seviyesi not). Real Chromium via
 * pest-plugin-browser: the "Klinik Notu" inline quick-edit on the patient Show
 * page must mount, render the note section with the pencil affordance (for an
 * authorized user), open the editor on click, and produce no JavaScript errors.
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

it('renders the patient show page with the inline note editor and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Create a patient with a known note so the note body renders.
    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Demir',
        'notes' => 'Diyabetik, adrenalin yok.',
    ]);

    $this->actingAs($owner);

    visit("/patients/{$patient->id}")
        ->assertNoJavascriptErrors()
        // Patient name is in the PageHeader — page-body content.
        ->assertSee('Ayşe Demir')
        // "Klinik Notu" is the notes section heading rendered inside the page component.
        ->assertSee('Klinik Notu')
        // The patient note text itself is rendered inside the notes block.
        ->assertSee('Diyabetik, adrenalin yok.')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // The pencil "Notu düzenle" button must be present for the owner.
        ->assertScript(
            '() => !!document.querySelector("[aria-label=\"Notu düzenle\"]")',
        )
        // Click the edit button to open the inline editor.
        ->click('[aria-label="Notu düzenle"]')
        ->waitForEvent('networkidle')
        // After clicking, the Textarea should appear.
        ->assertScript(
            '() => !!document.querySelector("textarea")',
        )
        // "Kaydet" save button should be visible in edit mode.
        ->assertSee('Kaydet')
        // "Vazgeç" cancel button (common.cancel) should be visible in edit mode.
        ->assertSee('Vazgeç')
        ->assertNoJavascriptErrors()
        ->screenshot();
});
