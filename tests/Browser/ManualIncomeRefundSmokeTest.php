<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Clinic;
use App\Models\Transaction;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — feature 1.69 fix D (manual income refund action). Real Chromium via
 * pest-plugin-browser: /incomes must render the refund button on a refundable row for an owner
 * and open the reused RefundDialog on click.
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

it('renders the refund button on a refundable income row and opens the refund dialog', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    Transaction::create([
        'clinic_id' => $clinic->id,
        'patient_id' => null,
        'treatment_id' => null,
        'amount' => '250.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
        'category' => 'Kira geliri',
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    $page = visit('/incomes');

    $page->assertNoJavascriptErrors()
        ->assertSee('Gelirler')
        ->assertSee('Kira geliri')
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->assertScript(
            '() => !!document.querySelector("[aria-label=\"İade et\"]")',
        );

    $page->click('[aria-label="İade et"]')
        ->assertSee('İade Yap')
        ->assertSee('İade tutarı')
        ->assertNoJavascriptErrors()
        ->screenshot();
});
