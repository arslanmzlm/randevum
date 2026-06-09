<?php

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.21b (Doctor offboarding). Real Chromium via
 * pest-plugin-browser: the /doctors list renders its (action-free) rows, and the
 * doctor edit page exposes the "İşten Çıkar" danger-zone action whose dialog
 * fetches the preview and renders body content without JavaScript errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component setup/render errors and
 * leaves <main> as an empty comment. The assertSee() calls on in-body labels plus
 * the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the doctors index list without JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $doctorUser = User::factory()->create();
    Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
        'specialization' => 'Kardiyoloji',
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit('/doctors')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Page-body subtitle rendered by PageHeader — inside the page component, not the app shell.
        ->assertSee('Kliniğinizin doktorlarını yönetin.')
        // Doctor's specialization proves the list card rendered real rows.
        ->assertSee('Kardiyoloji')
        // Guard: non-empty main body (a blank Vue mount renders as an empty comment).
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('opens the offboard dialog from the doctor edit page without JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // A different user/doctor (not self, is_active=true, left_at=null → not offboarded),
    // so the danger-zone "İşten Çıkar" action is gated as visible.
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
        'specialization' => 'Podoloji',
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit("/doctors/{$doctor->id}/edit")
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // The "İşlemler" actions card (under the avatar) hosts the offboard action — on the edit page, not the list.
        ->assertSee('İşlemler')
        // Click the "İşten Çıkar" button to open the OffboardDialog.
        ->click('İşten Çıkar')
        // Wait for the dialog's preview XHR fetch to settle.
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Dialog header — rendered inside PrimeVue Dialog, not the app shell or nav.
        ->assertSee('Doktoru İşten Çıkar')
        // No upcoming appointments → info-message text from offboard_warning_none key.
        ->assertSee('Yaklaşan randevusu yok')
        // The confirm/cancel footer buttons are inside the dialog body.
        ->assertSee('Vazgeç')
        // Guard: main is still non-empty after dialog interaction.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
