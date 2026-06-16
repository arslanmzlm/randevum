<?php

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
 * Browser smoke — Feature 1.3 (Dashboard stat cards + takvim özet + widgets). Real Chromium via
 * pest-plugin-browser: /dashboard must mount stat cards, the takvim-özet panel, and the
 * FollowUpWidget for a fully-permissioned owner, with no JS errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only catches *uncaught*
 * window errors. Vue swallows component setup/render errors and renders an empty comment in <main>.
 * The assertSee() calls on in-body labels plus the non-empty <main> script assert provide the real
 * guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders stat cards and takvim özet on the dashboard with no JS errors', function (): void {
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

    // Seed one today appointment so stat cards show non-zero counts and the takvim özet
    // has at least one row to render (guards against the empty-state path hiding mount failures).
    // Patient and doctor must be in the same clinic so ClinicScope resolves them (avoids NPE in DashboardStatsService::todaySchedule).
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'starts_at' => now()->setHour(10)->setMinute(0),
        'ends_at' => now()->setHour(10)->setMinute(30),
    ]);

    $this->actingAs($owner);

    visit('/dashboard')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Stat card labels — rendered by StatCardsRow inside the page body.
        ->assertSee('Bugünkü randevular')
        ->assertSee('Onay bekleyen')
        // Takvim özet section heading — rendered by TodayScheduleSummary.
        ->assertSee('Bugünün programı')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
