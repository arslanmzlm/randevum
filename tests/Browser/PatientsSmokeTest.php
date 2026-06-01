<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.8 (Patient records). Real Chromium via
 * pest-plugin-browser: the patients index and show pages must mount, render
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

it('renders the patients index page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed a patient with a known name so the list renders real rows + paginator.
    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Zeynep',
        'last_name' => 'Arslan',
    ]);

    $this->actingAs($owner);

    visit('/patients')
        ->assertNoJavascriptErrors()
        // Page subtitle is rendered inside the page component's PageHeader —
        // lives in the page body, not in the shared app shell.
        ->assertSee('Kliniğinizin hasta kayıtlarını yönetin.')
        // Patient name rendered inside the DataTable row.
        ->assertSee('Zeynep Arslan')
        // "Telefon" column header rendered inside the DataTable header row.
        ->assertSee('Telefon')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the patient show page with profile sections and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Mehmet',
        'last_name' => 'Kaya',
    ]);

    $this->actingAs($owner);

    visit("/patients/{$patient->id}")
        ->assertNoJavascriptErrors()
        // Profile section heading — rendered in the page body, not the shell.
        ->assertSee('Hasta Bilgileri')
        // Treatment history section heading — also in the page body.
        ->assertSee('Tedavi Geçmişi')
        // Patient's full name rendered by the PageHeader component.
        ->assertSee('Mehmet Kaya')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
