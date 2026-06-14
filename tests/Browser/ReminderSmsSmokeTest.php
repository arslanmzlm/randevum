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
 * Browser smoke — Feature 1.16 (Otomatik hatırlatma SMS). Real Chromium via
 * pest-plugin-browser: the /appointments list page must mount, render the
 * "Hatırlatma gönder" row-menu item (gated by canSendReminder client-side
 * logic: permission + future + confirmed/rescheduled status), and produce no
 * JavaScript errors.
 *
 * The cron command itself is UI-less; this smoke validates the GATE-1 RESOLVED
 * manual-send UI: the kebab row action and the calendar popover button, which
 * exercise the canSendReminder composable gate and the ConfirmDialog trigger.
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

it('renders the appointment list with the send-reminder row action for a future confirmed appointment', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // owner has appointments.sendReminder (seeded by PermissionSeeder).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);

    // Patient with a phone so the reminder is meaningful (phone check is server-side, but
    // the canSendReminder gate only requires permission + status + future starts_at).
    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Demir',
        'phone' => '+905551234567',
    ]);

    // Future confirmed appointment — canSendReminder requires confirmed/rescheduled + !isPast.
    $startsAt = CarbonImmutable::now()->addDays(1);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $startsAt->utc(),
        'ends_at' => $startsAt->addMinutes(30)->utc(),
        'reminder_24h_sent' => false,
    ]);

    $this->actingAs($owner);

    visit('/appointments')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Column header rendered in the page body (not the app shell).
        ->assertSee('Tarih / Saat')
        ->assertSee('Hasta')
        // Patient row must be rendered — proves the DataTable mounted with data.
        ->assertSee('Ayşe Demir')
        // The kebab (row actions) button must be present in the DOM — the canSendReminder
        // gate makes hasActions() true, so the button is rendered even if no other actions are.
        ->assertScript(
            '() => !!document.querySelector(\'[aria-label="Randevu işlemleri"]\')',
        )
        // Guard against the silent-blank-body false green.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // Open the row kebab and assert "Hatırlatma gönder" appears.
        ->click('[aria-label="Randevu işlemleri"]')
        ->assertSee('Hatırlatma gönder')
        ->screenshot();
});

it('renders the calendar popover with the send-reminder button for a future confirmed appointment', function (): void {
    // All-open working hours so the event chip always lands in a visible week column.
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
        'phone' => '+905559876543',
    ]);

    // Next week's Monday 10:00 so the slot is always in the future — canSendReminder
    // requires !isPast(starts_at). The calendar loads the current week by default, so
    // we click "Sonraki" to navigate to next week before clicking the chip.
    $slot = CarbonImmutable::now('Europe/Istanbul')
        ->addWeek()
        ->startOfWeek(CarbonImmutable::MONDAY)
        ->setTime(10, 0);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $slot->utc(),
        'ends_at' => $slot->addMinutes(30)->utc(),
        'reminder_24h_sent' => false,
    ]);

    $this->actingAs($owner);

    visit('/calendar')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Navigate to next week (where the future appointment lives).
        ->click('[aria-label="Sonraki"]')
        ->waitForEvent('networkidle')
        // Click the event chip to open the appointment popover.
        ->click('.calendar-event-chip')
        // The send-reminder button lives in the popover footer — its label is gated by
        // canSendReminder (permission + confirmed/rescheduled + !isPast).
        ->assertSee('Hatırlatma gönder')
        // Guard against the silent-blank-body false green.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
