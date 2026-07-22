<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PatientSegment;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.36 (Hasta CRM: etiket + segment). Real Chromium via
 * pest-plugin-browser: the patients index (tag column + tag/last-visit filters +
 * segment picker) and tags settings page (CRUD dialog) must mount, render
 * page-body content (not just the app shell), and produce no JavaScript errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors()
 * only catches *uncaught* window errors. Vue swallows component setup/render
 * errors and leaves <main> as an empty comment. The assertSee() calls on
 * in-body labels plus the non-empty <main> script assert provide the real guard.
 *
 * Assertion targets deliberately avoid "Etiketler" alone — it also appears in the
 * AppLayout sidebar nav entry (nav.tags), so it would false-green even if the page
 * body failed to mount. Seeded tag/segment names and distinctive filter copy
 * (only rendered by the page component) are used instead.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the patients index page with tag column, filters and segment picker with no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $tag = Tag::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'VIP',
        'color' => '#0d9488',
    ]);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Zeynep',
        'last_name' => 'Arslan',
    ]);
    $patient->tags()->attach($tag);

    PatientSegment::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Sadık Hastalar',
        'criteria' => ['is_legacy' => true],
    ]);

    $this->actingAs($owner);

    visit('/patients')
        ->assertNoJavascriptErrors()
        // Page subtitle rendered inside the page component's PageHeader — page body, not shell.
        ->assertSee('Kliniğinizin hasta kayıtlarını yönetin.')
        // Seeded tag chip on the patient row — proves the tag column + PatientResource tags relation.
        ->assertSee('VIP')
        // Segments dropdown placeholder — proves the SegmentPicker mounted (custom PrimeVue Select
        // renders its placeholder as label text, unlike a native input).
        ->assertSee('Segment seç')
        // Last-visit "before" filter — the DatePicker renders its placeholder as a native `<input>`
        // attribute, not innerText, so assert via the DOM rather than assertSee().
        ->assertScript(
            '() => document.querySelector(\'input[placeholder="Şu tarihten beri gelmeyen"]\') !== null',
        )
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the tags settings page and opens the create-tag dialog with no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    Tag::factory()->create([
        'clinic_id' => $clinic->id,
        'name' => 'Kronik',
        'color' => '#dc2626',
    ]);

    $this->actingAs($owner);

    $page = visit('/tags')
        ->assertNoJavascriptErrors()
        // Page subtitle in PageHeader — page-body content, distinctive to the tags settings page.
        ->assertSee('Hastaları gruplamak için klinik geneli etiketleri yönetin (örn. VIP, Kronik).')
        // "Hasta sayısı" column header — rendered inside the DataTable, page body.
        ->assertSee('Hasta sayısı')
        // Seeded tag chip rendered inside the DataTable row.
        ->assertSee('Kronik')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();

    // Open the create-tag dialog — the client-side create/edit toggle in TagFormDialog.
    $page->click('Etiket Ekle')
        ->assertNoJavascriptErrors()
        // Dialog header for create mode.
        ->assertSee('Etiket Ekle')
        // Name field label rendered inside the dialog form.
        ->assertSee('Etiket adı')
        ->screenshot();
});
