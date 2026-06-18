<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — header patient search. On a narrow viewport the docked
 * quick-access panel is hidden, so the header search button must open a popover
 * with the PatientSearchSelect input, which returns suggestions on keystrokes.
 *
 * assertNoJavascriptErrors() only catches *uncaught* errors; the popover-input
 * and suggestion-option assertions are the real guard against a silent failure.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('opens the patient search popover from the header button on a narrow viewport', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ahmet',
        'last_name' => 'Yılmaz',
    ]);

    $this->actingAs($owner);

    visit('/patients')
        // Below xl (1280px) the docked quick-access panel is hidden, so the only
        // path to the patient search is the header button → popover.
        ->resize(1024, 768)
        ->assertNoJavascriptErrors()
        // No patient-search input is in the DOM until the popover opens.
        ->assertScript(
            '() => !document.querySelector("input[placeholder*=\'Hasta ara\']")',
        )
        ->click('[aria-label="Hasta ara"]')
        ->assertScript(
            '() => !!document.querySelector("input[placeholder*=\'Hasta ara\']")',
        )
        ->typeSlowly('[placeholder*="Hasta ara"]', 'Ahm', 150)
        ->waitForEvent('networkidle')
        ->assertScript(
            '() => Array.from(document.querySelectorAll("[role=\"option\"]")).some(el => el.textContent.includes("Ahmet Yılmaz"))',
        )
        ->assertSee('Ahmet Yılmaz')
        ->assertNoJavascriptErrors()
        ->screenshot();
});
