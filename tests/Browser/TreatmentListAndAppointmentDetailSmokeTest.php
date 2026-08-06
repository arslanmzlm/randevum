<?php

use App\Enums\AppointmentStatus;
use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Core\Services\StatusLogService;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.63 (Tedaviler listesi + randevu detay sayfası, Dalga 2).
 * Real Chromium via pest-plugin-browser: /treatments (list) and /appointments/{id} (detail with
 * all four sections) must mount, render page-body content, and produce no JS errors.
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

it('renders the treatments list with filters and no JS errors', function (): void {
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
        'first_name' => 'Elif',
        'last_name' => 'Aydın',
    ]);

    Treatment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => TreatmentStatus::Completed,
        'total_amount' => 500,
    ]);

    $this->actingAs($owner);

    visit('/treatments')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Page title + patient row rendered inside the page body.
        ->assertSee('Tedaviler')
        ->assertSee('Elif Aydın')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the appointment detail page with all four sections and no JS errors', function (): void {
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
        'first_name' => 'Can',
        'last_name' => 'Öztürk',
    ]);

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Cancelled,
    ]);

    Treatment::factory()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => TreatmentStatus::Draft,
    ]);

    app(StatusLogService::class)->record($appointment, null, AppointmentStatus::Confirmed->value, $owner);
    app(StatusLogService::class)->record($appointment, AppointmentStatus::Confirmed->value, AppointmentStatus::Cancelled->value, $owner, 'Hasta iptal etti');

    SmsLog::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'loggable_type' => 'appointment',
        'loggable_id' => $appointment->id,
        'phone' => '+905301234567',
        'type' => SmsType::AppointmentCancelled->value,
        'body' => 'Randevunuz iptal edildi.',
        'status' => SmsStatus::Sent->value,
        'sent_at' => now(),
    ]);

    $this->actingAs($owner);

    visit("/appointments/{$appointment->id}")
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Section headings rendered inside the page body.
        ->assertSee('Can Öztürk')
        ->assertSee('Hasta iptal etti')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
