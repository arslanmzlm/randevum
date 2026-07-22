<?php

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Enums\TransactionStatus;
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
use Illuminate\Support\Collection;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role (cancel-payment-plan tests).
 */
function cpplRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A plan with 3 installments: one already Paid (with a linked Completed transaction) and
 * two still Pending — proves cancel only touches the pending ones.
 *
 * @return array{
 *   clinic: Clinic, owner: User, patient: Patient, plan: PaymentPlan,
 *   paidInstallment: PaymentPlanInstallment, pendingInstallments: Collection<int, PaymentPlanInstallment>,
 *   paidTransaction: Transaction,
 * }
 */
function cpplSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cpplRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'total_amount' => '300.00',
        'down_payment' => null,
        'installment_count' => 3,
    ]);

    $paidInstallment = PaymentPlanInstallment::factory()->paid()->create([
        'clinic_id' => $clinic->id,
        'payment_plan_id' => $plan->id,
        'sequence' => 1,
        'due_date' => now()->subMonth()->toDateString(),
        'amount' => '100.00',
    ]);

    $paidTransaction = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'payment_plan_installment_id' => $paidInstallment->id,
        'amount' => '100.00',
        'status' => TransactionStatus::Completed,
    ]);

    $pendingInstallments = collect([
        PaymentPlanInstallment::factory()->create([
            'clinic_id' => $clinic->id, 'payment_plan_id' => $plan->id,
            'sequence' => 2, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00',
        ]),
        PaymentPlanInstallment::factory()->create([
            'clinic_id' => $clinic->id, 'payment_plan_id' => $plan->id,
            'sequence' => 3, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => '100.00',
        ]),
    ]);

    return compact('clinic', 'owner', 'patient', 'plan', 'paidInstallment', 'pendingInstallments', 'paidTransaction');
}

// ---------------------------------------------------------------------------
// Happy path — cancel flips plan + pending installments only
// ---------------------------------------------------------------------------

it('cancels the plan and flips only its Pending installments to Cancelled', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'paidInstallment' => $paidInstallment, 'pendingInstallments' => $pendingInstallments] = cpplSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.cancel', $plan))
        ->assertRedirect();

    expect($plan->fresh()->status)->toBe(PaymentPlanStatus::Cancelled);

    foreach ($pendingInstallments as $installment) {
        expect($installment->fresh()->status)->toBe(InstallmentStatus::Cancelled);
    }

    // The already-Paid installment is untouched.
    expect($paidInstallment->fresh()->status)->toBe(InstallmentStatus::Paid);
});

it('leaves already-collected transactions untouched (no reversal, no refund) on cancel', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'paidTransaction' => $paidTransaction] = cpplSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.cancel', $plan));

    $fresh = $paidTransaction->fresh();
    expect($fresh->status)->toBe(TransactionStatus::Completed)
        ->and((float) $fresh->amount)->toBe(100.0);
});

it('writes an active → cancelled status_log for the plan', function (): void {
    ['owner' => $owner, 'plan' => $plan] = cpplSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.cancel', $plan));

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan')
        ->where('loggable_id', $plan->id)
        ->where('to_status', 'cancelled')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('active')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('writes a pending → cancelled status_log for each pending installment', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'pendingInstallments' => $pendingInstallments] = cpplSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.cancel', $plan));

    foreach ($pendingInstallments as $installment) {
        $log = StatusLog::withoutGlobalScopes()
            ->where('loggable_type', 'payment_plan_installment')
            ->where('loggable_id', $installment->id)
            ->where('to_status', 'cancelled')
            ->first();

        expect($log)->not->toBeNull()
            ->and($log->from_status)->toBe('pending');
    }
});
