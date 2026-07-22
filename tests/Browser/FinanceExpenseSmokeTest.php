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
 * pest-plugin-browser: the manager-only finance overview (/reports/finance — revenue + expense
 * + net) and the all-staff Giderlerim self-service page (/expenses) must both mount, render
 * page-body content (not just the app shell), and produce no JavaScript errors. Also exercises
 * the add-expense dialog (genuine client-side interaction) on Giderlerim.
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

    visit('/reports/finance')
        ->assertNoJavascriptErrors()
        // PageHeader title — finance.title, in the page body.
        ->assertSee('Finans')
        // Stat card + breakdown section headings — management-only figures.
        ->assertSee('Gelir kırılımı')
        ->assertSee('Gider kırılımı (kategori)')
        // Seeded expense's category rendered inside the all-clinic expense list.
        ->assertSee('Kira')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders Giderlerim for a receptionist and opens the add-expense dialog, with no JS errors', function (): void {
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
        ->assertSee('Giderlerim')
        // Own expense's category rendered inside the list.
        ->assertSee('Malzeme')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        );

    // Genuine client-side interaction: opening the dialog mounts the AutoComplete/DatePicker
    // fields and the dialog-only "Kaydet" submit button (not present elsewhere on the page).
    $page->click('Gider Ekle')
        ->assertNoJavascriptErrors()
        ->assertSee('Kaydet')
        ->screenshot();
});
