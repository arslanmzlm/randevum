<?php

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
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

function picRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A single 300.00 installment on a patient-general plan (treatment_id null).
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, plan: PaymentPlan, installment: PaymentPlanInstallment}
 */
function picSetup(float $amount = 300.00): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    picRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'total_amount' => number_format($amount, 2, '.', ''),
        'down_payment' => null,
        'installment_count' => 1,
    ]);

    $installment = PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinic->id,
        'payment_plan_id' => $plan->id,
        'sequence' => 1,
        'due_date' => now()->addMonth()->toDateString(),
        'amount' => number_format($amount, 2, '.', ''),
    ]);

    return compact('clinic', 'owner', 'patient', 'plan', 'installment');
}

// ---------------------------------------------------------------------------
// Partial collect — leaves partially_paid, plan stays Active
// ---------------------------------------------------------------------------

it('a partial collect leaves the installment partially_paid with the correct remaining and the plan Active', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '100.00'])
        ->assertRedirect();

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::PartiallyPaid)
        ->and($installment->paid_at)->toBeNull();

    $tx = Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installment->id)->first();
    expect((float) $tx->amount)->toBe(100.0);

    expect($plan->fresh()->status)->toBe(PaymentPlanStatus::Active);
});

it('writes a pending → partially_paid status_log for the partial collection', function (): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '100.00']);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan_installment')
        ->where('loggable_id', $installment->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('pending')
        ->and($log->to_status)->toBe('partially_paid');
});

// ---------------------------------------------------------------------------
// Completing the remainder → Paid + plan Completed
// ---------------------------------------------------------------------------

it('collecting the remainder flips the installment to Paid and completes the plan', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '100.00']);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment->fresh()), ['payment_method' => 'card', 'amount' => '200.00'])
        ->assertRedirect();

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::Paid)
        ->and($installment->paid_at)->not->toBeNull();

    expect($plan->fresh()->status)->toBe(PaymentPlanStatus::Completed);

    // Two linked transactions, summing to the full installment amount.
    $sum = Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installment->id)->sum('amount');
    expect((float) $sum)->toBe(300.0);
});

it('an omitted amount still collects the whole remaining (back-compat with full collection)', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash'])
        ->assertRedirect();

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::Paid);

    $tx = Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installment->id)->first();
    expect((float) $tx->amount)->toBe(300.0);

    expect($plan->fresh()->status)->toBe(PaymentPlanStatus::Completed);
});

it('an omitted amount on a partially-collected installment collects the remaining, not the full amount', function (): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '120.00']);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment->fresh()), ['payment_method' => 'cash'])
        ->assertRedirect();

    $tx = Transaction::withoutGlobalScopes()
        ->where('payment_plan_installment_id', $installment->id)
        ->orderByDesc('id')
        ->first();

    expect((float) $tx->amount)->toBe(180.0)
        ->and($installment->fresh()->status)->toBe(InstallmentStatus::Paid);
});

// ---------------------------------------------------------------------------
// Guard — amount above the remaining is rejected (422)
// ---------------------------------------------------------------------------

it('rejects a collection amount above the installment remaining', function (): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '350.00'])
        ->assertSessionHasErrors('amount');

    expect(Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installment->id)->count())->toBe(0);
});

it('rejects a second collection amount above the remaining after a partial collect', function (): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '100.00']);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment->fresh()), ['payment_method' => 'cash', 'amount' => '250.00'])
        ->assertSessionHasErrors('amount');

    expect($installment->fresh()->status)->toBe(InstallmentStatus::PartiallyPaid);
});

it('rejects a zero or negative collection amount', function (string $amount): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => $amount])
        ->assertSessionHasErrors('amount');
})->with(['0', '0.00', '-10.00']);

// ---------------------------------------------------------------------------
// Guard — a Paid/Cancelled installment cannot be collected, even partially
// ---------------------------------------------------------------------------

it('rejects collecting a Paid installment', function (): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);
    $installment->update(['status' => InstallmentStatus::Paid, 'paid_at' => now()]);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '50.00'])
        ->assertSessionHasErrors('installment');
});

it('rejects collecting a Cancelled installment', function (): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);
    $installment->update(['status' => InstallmentStatus::Cancelled]);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '50.00'])
        ->assertSessionHasErrors('installment');
});

// ---------------------------------------------------------------------------
// Collections screen — keeps showing a partially_paid row; stats sum remaining
// ---------------------------------------------------------------------------

it('the collections screen keeps a partially_paid installment visible with collected/remaining amounts', function (): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '100.00']);

    $this->actingAs($owner)
        ->get(route('payment-plans.installments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('installments', 1)
            ->where('installments.0.id', $installment->id)
            ->where('installments.0.status', InstallmentStatus::PartiallyPaid->value)
            ->where('installments.0.collected_amount', '100.00')
            ->where('installments.0.remaining_amount', '200.00')
        );
});

it("the collections screen's overdue stats sum the remaining, not the full installment amount", function (): void {
    ['owner' => $owner, 'installment' => $installment] = picSetup(300.00);
    $installment->update(['due_date' => now()->subDays(3)->toDateString()]);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '100.00']);

    $this->actingAs($owner)
        ->get(route('payment-plans.installments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('stats.overdue_count', 1)
            ->where('stats.overdue_total', '200.00')
        );
});

// ---------------------------------------------------------------------------
// Cancel — a plan cancel also cancels a partially_paid installment
// ---------------------------------------------------------------------------

it('cancelling a plan also cancels a partially_paid installment', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'installment' => $installment] = picSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '100.00']);

    $this->actingAs($owner)
        ->post(route('payment-plans.cancel', $plan->fresh()))
        ->assertRedirect();

    expect($installment->fresh()->status)->toBe(InstallmentStatus::Cancelled);
});
