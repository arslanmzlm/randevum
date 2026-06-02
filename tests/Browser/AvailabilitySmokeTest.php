<?php

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\ScheduleException;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.15 (Doctor availability / exceptions). Real
 * Chromium via pest-plugin-browser: the availability index must mount, render
 * page-body content (not just the app shell), and produce no JavaScript errors.
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

it('renders the availability index page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/schedule-exceptions')
        ->assertNoJavascriptErrors()
        // Page subtitle rendered inside PageHeader — lives in the page body, not the shell.
        ->assertSee('Doktorların izin, rapor ve kapalı günlerini yönetin.')
        // Add button label rendered in PageHeader actions — in-body, not shared nav.
        ->assertSee('İzin / Kapalı Gün Ekle')
        // Empty state text rendered by the page component when no exceptions exist.
        ->assertSee('Yaklaşan müsaitlik istisnası yok.')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the availability index with seeded exception rows', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed an active doctor with a known exception so the DataTable renders rows.
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
        'is_active' => true,
    ]);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'reason' => 'Seminer',
        'is_all_day' => true,
    ]);

    $this->actingAs($owner);

    visit('/schedule-exceptions')
        ->assertNoJavascriptErrors()
        // Page subtitle is in-body (PageHeader).
        ->assertSee('Doktorların izin, rapor ve kapalı günlerini yönetin.')
        // "Tarih / Saat" column header rendered in the DataTable — in page body.
        ->assertSee('Tarih / Saat')
        // The seeded reason must appear in the DataTable row.
        ->assertSee('Seminer')
        // Guard against the silent-blank-body false green.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
