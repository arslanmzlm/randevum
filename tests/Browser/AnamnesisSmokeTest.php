<?php

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryAnamnesis;
use App\Models\User;
use App\Models\Vertical;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — patient detail page mounts the Anamnesis section. Real Chromium via
 * pest-plugin-browser: patients/Show must render the "Anamnez" section title and its
 * "Anamnez (PDF)" download action wired to the `patients.anamnesis.pdf` route. PDF
 * generation itself (headless Chromium → binary stream) is proven server-side in
 * tests/Feature/Anamnesis/AnamnesisReportTest.php — this smoke only proves the section
 * and action render, since Inertia cannot follow a binary-file link.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only catches
 * *uncaught* window errors. Vue swallows component setup/render errors and leaves <main> as
 * an empty comment. The assertSee() calls on in-body labels plus the non-empty <main> script
 * assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the patient show page with the Anamnesis section and no JS errors', function (): void {
    $vertical = Vertical::factory()->podiatry()->create();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);

    $ownerUser = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $ownerUser->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // The PDF action only renders once the form holds data (an empty anamnesis PDF is noise),
    // so the patient needs a filled record for the action assertions below.
    $anamnesis = PodiatryAnamnesis::create(['blood_type' => 'A+', 'height_cm' => 172]);
    $patient->forceFill([
        'anamnesis_type' => 'podiatry_anamnesis',
        'anamnesis_id' => $anamnesis->id,
    ])->save();

    $this->actingAs($ownerUser);

    visit("/patients/{$patient->id}")
        ->assertNoJavascriptErrors()
        // Anamnesis section title + patient name (page body, not shell/nav).
        ->assertSee('Anamnez')
        ->assertSee(trim($patient->first_name.' '.$patient->last_name))
        // The PDF download action's label — in-body, part of SectionCard's #actions slot.
        ->assertSee('Anamnez (PDF)')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // The action must be a plain anchor pointed at the PDF route (never an Inertia
        // <Link>/router.visit, which cannot handle a binary application/pdf response).
        ->assertScript(
            "() => { const a = [...document.querySelectorAll('a')].find(el => el.textContent.includes('Anamnez (PDF)')); return !!a && a.getAttribute('href') === '/patients/{$patient->id}/anamnesis/pdf' && a.getAttribute('target') === '_blank'; }",
        )
        ->screenshot();
});
