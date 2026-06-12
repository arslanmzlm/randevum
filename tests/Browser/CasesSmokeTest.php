<?php

use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Models\Vertical;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.11 (Vaka yönetimi). Real Chromium via
 * pest-plugin-browser: the cases/Index and cases/Show pages must mount, render
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

/**
 * Assign a clinic-scoped role via Spatie Teams (smoke-test local helper).
 */
function caseSmokeRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Shared setup: clinic (with vertical) + owner + doctor + patient + case.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, caseRecord: CaseRecord}
 */
function csSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    caseSmokeRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $caseRecord = CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'vertical_id' => $clinic->vertical_id,
        'title' => 'Tanı Değerlendirme Vakası',
    ]);

    return compact('clinic', 'owner', 'doctor', 'patient', 'caseRecord');
}

it('renders the cases index page with table content and no JS errors', function (): void {
    ['owner' => $owner] = csSetup();

    $this->actingAs($owner);

    visit('/cases')
        ->assertNoJavascriptErrors()
        // PageHeader subtitle — rendered by the page component, not the shell.
        ->assertSee('Hastaların tedavi vakalarını görüntüleyin ve yönetin.')
        // Case title rendered inside the DataTable row — proves the row mounted.
        ->assertSee('Tanı Değerlendirme Vakası')
        // "Açılış" column header — lives inside the DataTable, not the nav/shell.
        ->assertSee('Açılış')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the cases show page with clinical sections and no JS errors', function (): void {
    ['owner' => $owner, 'caseRecord' => $caseRecord] = csSetup();

    $this->actingAs($owner);

    visit("/cases/{$caseRecord->id}")
        ->assertNoJavascriptErrors()
        // Section heading from the case-info panel — inside the page body.
        ->assertSee('Vaka Bilgileri')
        // Notes section heading — rendered by the Show page body sidebar.
        ->assertSee('Vaka Notu')
        // Treatments section heading — in the main content area.
        ->assertSee('Tedaviler')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
