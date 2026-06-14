<?php

use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.24 (Follow-up hatırlatma). Real Chromium via
 * pest-plugin-browser: the /dashboard page must mount the FollowUpWidget, render
 * a seeded due follow-up row (patient name + column headers), and produce no JS errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component setup/render errors and
 * renders an empty comment in <main>. The assertSee() calls on in-body widget labels
 * plus the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the dashboard follow-up widget with a due row and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $owner->unsetRelation('roles');
    $owner->unsetRelation('permissions');

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Fatma',
        'last_name' => 'Kaya',
    ]);

    // Seed an overdue follow-up (2 days ago) so the widget renders a real row.
    CaseRecord::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'vertical_id' => $clinic->vertical_id,
        'follow_up_date' => now()->subDays(2)->toDateString(),
        'follow_up_note' => 'Kontrol randevusu için aranacak.',
    ]);

    $this->actingAs($owner);

    visit('/dashboard')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Widget card title — rendered by FollowUpWidget inside the page body.
        ->assertSee('Bugün aranacaklar')
        // Column header from the DataTable — lives inside the widget, not the app shell.
        ->assertSee('Hasta')
        // Seeded patient name inside the DataTable row — proves the row actually mounted.
        ->assertSee('Fatma Kaya')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
