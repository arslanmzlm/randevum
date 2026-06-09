<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.7 (Randevu onay / iptal / değişiklik). Real Chromium via
 * pest-plugin-browser: the /appointments list page (with row actions column) and the
 * /appointments/{id}/edit page must mount, render page-body content, and produce no JS errors.
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

it('renders the appointment list with the row actions column and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
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

    // A future confirmed appointment so the row actions button is gated as visible.
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner);

    visit('/appointments')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // DataTable column headers rendered inside the page body (not the nav/shell).
        ->assertSee('İşlemler')
        ->assertSee('Tarih / Saat')
        // Patient name rendered inside a DataTable row.
        ->assertSee('Ayşe Demir')
        // The kebab actions button must be in the DOM (aria-label = appointment_actions.row_actions).
        ->assertScript(
            '() => !!document.querySelector(\'[aria-label="Randevu işlemleri"]\')',
        )
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('opens the calendar event popover with lifecycle action buttons and no JS errors', function (): void {
    // Open every weekday so the placed event always lands in a rendered week-view column.
    $allOpen = ['open' => '00:00', 'close' => '23:59', 'break' => null];
    $clinic = Clinic::factory()->create([
        'working_hours' => [
            'monday' => $allOpen,
            'tuesday' => $allOpen,
            'wednesday' => $allOpen,
            'thursday' => $allOpen,
            'friday' => $allOpen,
            'saturday' => $allOpen,
            'sunday' => $allOpen,
        ],
    ]);
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
        'first_name' => 'Zeynep',
        'last_name' => 'Yıldız',
    ]);

    // This week's Monday 10:00 clinic-local: always inside the default week view's fetch range.
    // Edit/cancel don't require a future slot, so the day being past (mid-week) is fine.
    $slot = CarbonImmutable::now('Europe/Istanbul')
        ->startOfWeek(CarbonImmutable::MONDAY)
        ->setTime(10, 0);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $slot->utc(),
        'ends_at' => $slot->addMinutes(30)->utc(),
    ]);

    $this->actingAs($owner);

    visit('/calendar')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Click the event chip to open its popover.
        ->click('.calendar-event-chip')
        // Popover footer exposes the shared lifecycle actions (same as the list row menu).
        ->assertSee('Düzenle')
        ->assertSee('İptal et')
        ->screenshot();
});

it('renders the appointment edit page with form sections and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
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
        'first_name' => 'Mehmet',
        'last_name' => 'Kaya',
    ]);

    // A future confirmed appointment (factory default) so the edit page is accessible.
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner);

    visit("/appointments/{$appointment->id}/edit")
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Page title rendered by PageHeader — in the page body, not the app shell.
        ->assertSee('Randevuyu Düzenle')
        // DateTimeFields section heading — confirms the date/time card rendered.
        ->assertSee('Tarih ve Saat')
        // Save button label confirms the form rendered (not just the surrounding shell).
        ->assertSee('Değişiklikleri Kaydet')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
