<?php

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.20 (Doctor profile). Real Chromium via
 * pest-plugin-browser: the doctors index page must mount, render page-body
 * content (not just the app shell), and produce no JavaScript errors.
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

it('renders the doctors index page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed a doctor so the list renders real rows (not just the empty state).
    $doctorUser = User::factory()->create();
    Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
        'specialization' => 'Podoloji',
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit('/doctors')
        ->assertNoJavascriptErrors()
        // Subtitle is rendered inside the page component's PageHeader —
        // lives in the page body, not in the shared app shell.
        ->assertSee('Kliniğinizin doktorlarını yönetin.')
        // Doctor's specialization is rendered inside the list card.
        ->assertSee('Podoloji')
        // Active status badge rendered on the list card row.
        ->assertSee('Aktif')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
