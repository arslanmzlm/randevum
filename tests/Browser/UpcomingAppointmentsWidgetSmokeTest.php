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
 * Browser smoke — Feature 1.61 (Yaklaşan randevular widget). Real Chromium via
 * pest-plugin-browser: on a wide viewport the right-hand quick-access sidebar is docked
 * open for a user with appointments.viewAny, with the upcoming-appointments widget
 * inside it rendering the seeded row from the upcomingAppointments shared prop.
 *
 * This exercises the real client-side path: the persistent quick-access aside mounting
 * the widget and rendering the shared-prop list (UpcomingAppointmentsList) without a fetch.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component setup/render errors and
 * renders an empty comment in <main>. The assertSee() calls on in-body labels plus
 * the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('docks the quick-access sidebar with the upcoming widget and seeded row on a wide viewport', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed a future Confirmed appointment so the widget renders a real row from the shared prop.
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);
    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Zeynep',
        'last_name' => 'Arslan',
    ]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => now()->addDay(),
    ]);

    $this->actingAs($owner);

    // Wide enough (>= xl) for the quick-access aside to dock open by default.
    visit('/dashboard')
        ->resize(1440, 900)
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // In-body content from the Dashboard page component — fails when the page mounts blank.
        ->assertSee('Dashboard')
        ->assertSee('Panel içeriği yakında eklenecek.')
        // Guard against the silent-blank-body false green.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // Quick-access sidebar heading (rendered only when appointments.viewAny).
        ->assertSee('Hızlı erişim')
        // The upcoming widget title inside the quick-access area.
        ->assertSee('Yaklaşan randevular')
        // The seeded appointment row is shown directly — the docked aside seeds from
        // the upcomingAppointments shared prop (no click needed).
        ->assertSee('Zeynep Arslan')
        // Footer "Tüm randevular" link is rendered when the list is non-empty.
        ->assertSee('Tüm randevular')
        ->screenshot();
});
