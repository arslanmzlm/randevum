<?php

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.31 (Randevu türleri). Real Chromium via
 * pest-plugin-browser: the appointment-types index and create pages must mount,
 * render page-body content (not just the app shell), and produce no JS errors.
 * Also verifies the type select renders on the appointments/create form when
 * active types exist.
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

it('renders the appointment-types index page with body content and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Seed a type so the DataTable renders a real row.
    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Muayene',
        'color' => '#0d9488',
        'default_duration_minutes' => 30,
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit('/appointment-types')
        ->assertNoJavascriptErrors()
        // Page subtitle in the PageHeader component — page-body content, not app shell.
        ->assertSee('Takvimde renkle ayrışan randevu türlerini')
        // DataTable column header — rendered inside the table, in page body.
        ->assertSee('Varsayılan süre')
        // Seeded type name rendered inside the DataTable row.
        ->assertSee('Muayene')
        // Guard against silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the appointment-type create page with form sections and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    visit('/appointment-types/create')
        ->assertNoJavascriptErrors()
        // Section heading inside the form card — rendered in the page body.
        ->assertSee('Tür Bilgileri')
        // Field label for the color picker — rendered inside FormField.
        ->assertSee('Takvim rengi')
        // Page description in PageHeader — page-body content.
        ->assertSee('Yeni bir randevu türü tanımlayın.')
        // Guard against silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the appointment-type select on the create-appointment form when types exist', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Active type — must appear in the booking form's type select.
    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Kontrol',
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit('/appointments/create')
        ->assertNoJavascriptErrors()
        // Field label for the appointment type select — rendered in AppointmentDetailsFields.
        ->assertSee('Randevu türü')
        // Hint text below the type select — rendered in the page body.
        ->assertSee('Hizmet seçilmezse süre randevu türünün varsayılanından belirlenir.')
        // Guard against silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
