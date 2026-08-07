<?php

use App\Models\Clinic;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.67 (Manuel gelir + parçalı taksit). Real Chromium via
 * pest-plugin-browser: the manual-income list page (/incomes) must mount, render page-body
 * content (not just the app shell), produce no JavaScript errors, and its "add" dialog must open
 * (genuine client-side interaction — the create-only CrudDialog with no toForm/update).
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

it('renders the manual income page for an owner with a row and opens the add dialog, with no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'created_by' => $owner->id,
        'paid_at' => now(),
        'amount' => '250.00',
        'category' => 'Kira geliri',
    ]);

    $this->actingAs($owner);

    $page = visit('/incomes');

    $page->assertNoJavascriptErrors()
        // PageHeader title — income.title, in the page body.
        ->assertSee('Gelirler')
        // Seeded manual income row's category rendered inside the list.
        ->assertSee('Kira geliri')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        );

    // Genuine client-side interaction: opening the create-only CrudDialog (no toForm/update)
    // mounts ManualIncomeFields and the dialog-only "Geliri Ekle" submit button.
    $page->click('Gelir Ekle')
        ->assertNoJavascriptErrors()
        ->assertSee('Geliri Ekle')
        ->screenshot();
});
