<?php

use App\Models\Clinic;
use App\Models\FollowUp;
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

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Fatma',
        'last_name' => 'Kaya',
    ]);

    // Seed an overdue follow-up (2 days ago) so the widget renders a real row.
    // Feature 1.65 moved follow-ups off cases.follow_up_date/note into their own table.
    FollowUp::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'due_date' => now()->subDays(2)->toDateString(),
        'note' => 'Kontrol randevusu için aranacak.',
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
