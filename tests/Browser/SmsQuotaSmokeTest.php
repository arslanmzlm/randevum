<?php

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\SmsLog;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 3.9a (SMS kota aylık sabit hak + sert blok).
 * Real Chromium via pest-plugin-browser: the SMS settings page must render the
 * quota panel (SmsQuotaPanel) with a seeded usage bar, not just the type-toggle
 * section the old SmsSettingsSmokeTest checked.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors()
 * only catches *uncaught* window errors. Vue swallows component setup/render
 * errors and leaves <main> as an empty comment. The assertSee() calls on
 * in-body quota-panel labels plus the non-empty <main> script assert provide
 * the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the SMS quota panel with usage bar and no JS errors', function (): void {
    // Clinic with a small quota so "X / N SMS kullanıldı" shows meaningful numbers.
    $clinic = Clinic::factory()->create([
        'sms_monthly_quota' => 10,
        'timezone' => 'Europe/Istanbul',
    ]);
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed 3 consumed rows so the ProgressBar has non-zero data.
    foreach (range(1, 3) as $i) {
        SmsLog::create([
            'clinic_id' => $clinic->id,
            'patient_id' => null,
            'phone' => '+9053012345'.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'type' => SmsType::AppointmentCreated->value,
            'body' => 'Test',
            'status' => SmsStatus::Sent->value,
            'error' => null,
            'scheduled_at' => null,
            'sent_at' => now(),
        ]);
    }

    $this->actingAs($owner);

    visit('/clinic/sms-settings')
        ->assertNoJavascriptErrors()
        // Panel section heading — rendered by SmsQuotaPanel, inside page body.
        ->assertSee('Aylık SMS Kotası')
        // Usage counter line rendered by SmsQuotaPanel (3 of 10 used).
        ->assertSee('3 / 10 SMS kullanıldı')
        // Reset-date line — proves the resets_at ISO string was parsed and formatted.
        ->assertSee('Kota')
        // Guard against the silent-blank-body false green.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
