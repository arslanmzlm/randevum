<?php

use App\Models\Clinic;
use App\Models\Expense;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.35 (Gider takibi / birleşik finans sayfası). Real Chromium via
 * pest-plugin-browser: the manager-only finance overview (/reports, finance tab — revenue +
 * expense + net) and the all-staff expense page (/expenses) must both mount, render
 * page-body content (not just the app shell), and produce no JavaScript errors. Also exercises
 * the add-expense dialog (genuine client-side interaction) on the expense page.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only catches
 * *uncaught* window errors. Vue swallows component setup/render errors and leaves <main> as an
 * empty comment. The assertSee() calls on in-body labels plus the non-empty <main> script assert
 * provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function feRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('renders the finance overview for an owner with revenue, expense and net, and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    feRole($owner, 'owner', $clinic->id);

    Expense::factory()->create([
        'clinic_id' => $clinic->id,
        'created_by' => $owner->id,
        'expense_date' => now()->format('Y-m-d'),
        'amount' => '150.00',
        'category' => 'Kira',
    ]);

    $this->actingAs($owner);

    visit('/reports')
        ->assertNoJavascriptErrors()
        // PageHeader title — finance.title, in the page body.
        ->assertSee('Finans')
        // Breakdown card headings — management-only figures. The expense ROWS are not here:
        // they live on /expenses, which this page links to.
        ->assertSee('Ödeme yöntemine göre')
        ->assertSee('Gider kırılımı (kategori)')
        // The seeded expense's category still shows in the category breakdown.
        ->assertSee('Kira')
        ->assertSee('Giderler sayfasına git')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the doctor and service report breakdown tabs with no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    feRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner);

    visit('/reports?tab=doctor')
        ->assertNoJavascriptErrors()
        ->assertSee('Doktor')
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();

    visit('/reports?tab=service')
        ->assertNoJavascriptErrors()
        ->assertSee('Hizmet')
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the expense page for a receptionist and opens the add-expense dialog, with no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    feRole($receptionist, 'receptionist', $clinic->id);

    Expense::factory()->create([
        'clinic_id' => $clinic->id,
        'created_by' => $receptionist->id,
        'expense_date' => now()->format('Y-m-d'),
        'amount' => '75.00',
        'category' => 'Malzeme',
    ]);

    $this->actingAs($receptionist);

    $page = visit('/expenses');

    $page->assertNoJavascriptErrors()
        // PageHeader title — expense.title, in the page body.
        ->assertSee('Giderler')
        // Own expense's category rendered inside the list.
        ->assertSee('Malzeme')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        );

    // Genuine client-side interaction: opening the dialog mounts the AutoComplete/DatePicker
    // fields and the dialog-only "Gideri Ekle" submit button (not present elsewhere on the page).
    $page->click('Gider Ekle')
        ->assertNoJavascriptErrors()
        ->assertSee('Gideri Ekle')
        ->screenshot();
});
