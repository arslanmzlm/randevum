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

it('opens the appointment-type create dialog from the list with no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner);

    // ?new=1 is the shareable form of the dialog — the same state the "Tür Ekle" button sets.
    visit('/appointment-types?new=1')
        ->assertNoJavascriptErrors()
        // Dialog header — rendered by CrudDialog, not the list page.
        ->assertSee('Randevu Türü Ekle')
        // Field label for the color picker — rendered inside FormField.
        ->assertSee('Takvim rengi')
        // Submit label, so the shared frame's footer is proven to render.
        ->assertSee('Türü Ekle')
        // Guard against silent-blank-body false green: the dialog must carry content.
        ->assertScript(
            '() => (document.querySelector(".p-dialog")?.innerText.trim().length ?? 0) > 0',
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

it('creates a type from the booking form select without leaving the page', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Kontrol',
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    $page = visit('/appointments/create');

    // Open the type dropdown by its label, then its "add" footer action.
    $page->script('(() => { const l = Array.from(document.querySelectorAll("label")).find(x => x.textContent.trim().startsWith("Randevu türü")); l.closest(".p-floatlabel").querySelector(".p-select").dispatchEvent(new MouseEvent("click", {bubbles: true})); })()');
    $page->script('Array.from(document.querySelectorAll(".p-select-overlay button")).find(b => b.textContent.includes("Tür Ekle")).dispatchEvent(new MouseEvent("click", {bubbles: true}))');

    $page->assertSee('Randevu Türü Ekle')
        // The only fluid text input in the dialog is the name field (the colour input is fixed-width).
        ->type('.p-dialog input.p-inputtext-fluid', 'Hızlı Kontrol')
        ->click('Türü Ekle')
        ->assertNoJavascriptErrors()
        ->screenshot();

    // The row is stored and the page never navigated away from the booking form.
    expect(AppointmentType::where('name', 'Hızlı Kontrol')->exists())->toBeTrue();

    $page->assertUrlIs(config('app.url').'/appointments/create');
});
