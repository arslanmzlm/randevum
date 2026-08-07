<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Clinic;
use App\Models\StatusLog;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

function mirRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinic: Clinic, owner: User, income: Transaction}
 */
function mirSetup(float $amount = 300.00): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    mirRole($owner, 'owner', $clinic->id);

    $income = Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'amount' => number_format($amount, 2, '.', ''),
        'category' => 'Kira geliri',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subHour(),
        'created_by' => $owner->id,
    ]);

    return compact('clinic', 'owner', 'income');
}

// ---------------------------------------------------------------------------
// Owner refunds a manual income row (fix D — the RefundDialog reused on /incomes)
// ---------------------------------------------------------------------------

it('owner fully refunds a manual income row: the counter-entry is patient-less, carries the category, and the original flips to Refunded', function (): void {
    ['owner' => $owner, 'income' => $income] = mirSetup(300.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $income), ['amount' => '300.00', 'reason' => 'Yanlış girildi'])
        ->assertRedirect();

    $counter = Transaction::withoutGlobalScopes()->where('original_transaction_id', $income->id)->first();

    expect($counter)->not->toBeNull()
        ->and($counter->patient_id)->toBeNull()
        ->and((float) $counter->amount)->toBe(-300.0)
        ->and($counter->category)->toBe('Kira geliri')
        ->and($counter->original_transaction_id)->toBe($income->id)
        ->and($income->fresh()->status)->toBe(TransactionStatus::Refunded);
});

it('a partial then full refund on a manual income row logs completed→partially_refunded then partially_refunded→refunded', function (): void {
    ['owner' => $owner, 'income' => $income] = mirSetup(300.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $income), ['amount' => '100.00', 'reason' => 'Kısmi iade']);

    expect($income->fresh()->status)->toBe(TransactionStatus::PartiallyRefunded);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $income->fresh()), ['amount' => '200.00', 'reason' => 'Kalan iade']);

    expect($income->fresh()->status)->toBe(TransactionStatus::Refunded);

    $logs = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'transaction')
        ->where('loggable_id', $income->id)
        ->orderBy('id')
        ->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->from_status)->toBe('completed')
        ->and($logs[0]->to_status)->toBe('partially_refunded')
        ->and($logs[1]->from_status)->toBe('partially_refunded')
        ->and($logs[1]->to_status)->toBe('refunded');
});

// ---------------------------------------------------------------------------
// Authorization — transactions.refund stays owner-only, even for a manual income row
// ---------------------------------------------------------------------------

it('a receptionist (has transactions.viewAny/create, not transactions.refund) gets 403 refunding a manual income row', function (): void {
    ['clinic' => $clinic, 'income' => $income] = mirSetup(300.00);

    $receptionist = User::factory()->create();
    mirRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('transactions.refund', $income), ['amount' => '100.00', 'reason' => 'deneme'])
        ->assertForbidden();

    expect(Transaction::withoutGlobalScopes()->where('original_transaction_id', $income->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// GET /incomes exposes the refund seam (status, original_transaction_id, refundable_amount)
// ---------------------------------------------------------------------------

it('GET /incomes exposes status, original_transaction_id and refundable_amount, 0.00 for the counter-entry', function (): void {
    ['owner' => $owner, 'income' => $income] = mirSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $income), ['amount' => '80.00', 'reason' => 'Kısmi iade']);

    $counter = Transaction::withoutGlobalScopes()->where('original_transaction_id', $income->id)->first();

    $this->actingAs($owner)
        ->get(route('incomes.index', ['entire' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('incomes.data', 2)
            // Default sort is -id: the newer counter-entry precedes the original.
            ->where('incomes.data.0.id', $counter->id)
            ->where('incomes.data.0.status', 'refunded')
            ->where('incomes.data.0.original_transaction_id', $income->id)
            ->where('incomes.data.0.refundable_amount', '0.00')
            ->where('incomes.data.1.id', $income->id)
            ->where('incomes.data.1.status', 'partially_refunded')
            ->where('incomes.data.1.original_transaction_id', null)
            ->where('incomes.data.1.refundable_amount', '120.00')
        );
});
