<?php

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — treatment summary document (PDF). Real Chromium via
 * pest-plugin-browser: the treatments/Show page must mount, render its "Tedavi özeti (PDF)"
 * download action wired to the `treatments.report` route, and produce no JS errors. The PDF
 * generation itself (headless Chromium → binary stream) is proven server-side in
 * tests/Feature/Treatments/TreatmentReportTest.php — this smoke only proves the action renders
 * and points at the right URL, since Inertia cannot follow a binary-file link.
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

it('renders the treatment show page with the report download action and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();

    $ownerUser = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $ownerUser->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);
    $treatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);

    $this->actingAs($ownerUser);

    visit("/treatments/{$treatment->id}")
        ->assertNoJavascriptErrors()
        // Treatment Show page-body labels (not shell/nav) — clinical section + patient name.
        ->assertSee('Şikayet')
        ->assertSee(trim($patient->first_name.' '.$patient->last_name))
        // The report download action's label — in-body, part of PageHeader's #actions slot.
        ->assertSee('Tedavi özeti (PDF)')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // The action must be a plain anchor pointed at the report route (never an Inertia
        // <Link>/router.visit, which cannot handle a binary application/pdf response).
        ->assertScript(
            "() => { const a = [...document.querySelectorAll('a')].find(el => el.textContent.includes('Tedavi özeti')); return !!a && a.getAttribute('href') === '/treatments/{$treatment->id}/report' && a.getAttribute('target') === '_blank'; }",
        )
        ->screenshot();
});
