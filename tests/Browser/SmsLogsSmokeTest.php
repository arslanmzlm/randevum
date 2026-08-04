<?php

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.34 (SMS log görünümü). Real Chromium via
 * pest-plugin-browser: the /sms-logs list page and the patient-detail SMS
 * section must mount, render page-body content (not just the app shell),
 * and produce no JavaScript errors.
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

it('renders the SMS log list page with table headers and a data row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (has smsLogs.viewAny).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Demir',
    ]);

    // One sent appointment-created SMS so the DataTable renders a real row.
    SmsLog::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'phone' => '+905301234567',
        'type' => SmsType::AppointmentCreated->value,
        'body' => 'Randevunuz oluşturuldu.',
        'status' => SmsStatus::Sent->value,
        'error' => null,
        'sent_at' => now(),
    ]);

    $this->actingAs($owner);

    visit('/sms-logs')
        ->assertNoJavascriptErrors()
        // PageHeader title rendered inside the page body.
        ->assertSee('SMS Günlüğü')
        // Column header for the "Tarih / Saat" column — in the DataTable head, in-body.
        ->assertSee('Tarih / Saat')
        // Patient row — proves the DataTable mounted with real data.
        ->assertSee('Ayşe Demir')
        // Guard against the silent-blank-body false green.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the patient detail page with the communication history section', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Mehmet',
        'last_name' => 'Kaya',
    ]);

    // One SMS row for this patient so the section renders a real entry, not the empty state.
    SmsLog::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'phone' => '+905309876543',
        'type' => SmsType::Reminder24h->value,
        'body' => '24 saat hatırlatma mesajı.',
        'status' => SmsStatus::Sent->value,
        'error' => null,
        'sent_at' => now(),
    ]);

    $this->actingAs($owner);

    visit("/patients/{$patient->id}")
        ->assertNoJavascriptErrors()
        // Patient profile section heading — in-body, not the shell.
        ->assertSee('Hasta Bilgileri')
        // The SMS history sits on the İletişim tab since the page was split into tabs.
        ->click('#patient-tab-messages')
        ->assertSee('İletişim geçmişi')
        // SMS type label for the seeded row.
        ->assertSee('24 saat hatırlatma')
        // Guard against the silent-blank-body false green.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
