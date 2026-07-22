<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.37 (No-show yönetimi). Real Chromium via pest-plugin-browser:
 * the "Gelmeyenler" pre-filtered appointment list and the dashboard no-show stat tile must
 * mount, render page-body content, and produce no JS errors. Check-in/no-show actions
 * (calendar popover + list row menu) and the auto-sweep are already covered by HTTP feature
 * tests — this is a render-only smoke per the verify-phase scope.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only catches
 * *uncaught* window errors. Vue swallows component setup/render errors and leaves <main>
 * as an empty comment. The assertSee() calls on in-body labels plus the non-empty <main>
 * script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the Gelmeyenler pre-filtered appointment list with no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Demir',
    ]);

    // NoShow row — must appear under the preset filter.
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::NoShow,
    ]);

    // Confirmed row — must be excluded by the preset filter.
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => Patient::factory()->create([
            'clinic_id' => $clinic->id,
            'first_name' => 'Mehmet',
            'last_name' => 'Yılmaz',
        ])->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner);

    visit('/appointments?'.http_build_query(['filter' => ['status' => 'no_show']]))
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // DataTable column headers rendered inside the page body (not the nav/shell).
        ->assertSee('Tarih / Saat')
        ->assertSee('Hasta')
        // Only the NoShow patient's row should render under the preset filter.
        ->assertSee('Ayşe Demir')
        // The status tag for the filtered row.
        ->assertSee('Gelmedi')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the no-show rate stat tile on the dashboard with no JS errors', function (): void {
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

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // A resolved (NoShow) appointment this month so the tile renders a non-null percent
    // instead of the "—" empty state (guards against the empty-state path hiding a mount failure).
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::NoShow,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->subDay()->addMinutes(30),
    ]);

    $this->actingAs($owner);

    visit('/dashboard')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Existing stat card label — proves the shared row still mounts.
        ->assertSee('Bugünkü randevular')
        // No-show rate tile — rendered by StatCardsRow inside the page body.
        ->assertSee('No-show oranı (bu ay)')
        ->assertSee('100%')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
