<?php

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\StatusLog;
use App\Models\Transaction;
use App\Models\Treatment;
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
 * Assign a clinic-scoped role (collect-installment tests).
 */
function pciRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A patient-general plan (treatment_id null) with $count equal installments of
 * $installmentAmount each, no down payment.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, plan: PaymentPlan, installments: Collection<int, PaymentPlanInstallment>}
 */
function pciSetup(float $installmentAmount = 100.00, int $count = 2): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pciRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'total_amount' => number_format($installmentAmount * $count, 2, '.', ''),
        'down_payment' => null,
        'installment_count' => $count,
    ]);

    $installments = collect();
    for ($i = 1; $i <= $count; $i++) {
        $installments->push(PaymentPlanInstallment::factory()->create([
            'clinic_id' => $clinic->id,
            'payment_plan_id' => $plan->id,
            'sequence' => $i,
            'due_date' => now()->addMonths($i)->toDateString(),
            'amount' => number_format($installmentAmount, 2, '.', ''),
        ]));
    }

    return compact('clinic', 'owner', 'patient', 'plan', 'installments');
}

/**
 * A treatment-bound plan (2 installments) against a Completed treatment whose
 * total_amount equals the plan's total_amount — proves the treatment_id passthrough
 * and lets the existing overpayment guard be exercised.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, treatment: Treatment, plan: PaymentPlan, installments: Collection<int, PaymentPlanInstallment>}
 */
function pciTreatmentSetup(float $treatmentTotal = 200.00): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pciRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => $treatmentTotal,
        'discount_amount' => 0,
        'total_amount' => $treatmentTotal,
        'status' => TreatmentStatus::Completed,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'total_amount' => number_format($treatmentTotal, 2, '.', ''),
        'down_payment' => null,
        'installment_count' => 2,
    ]);

    $half = number_format($treatmentTotal / 2, 2, '.', '');
    $installments = collect([
        PaymentPlanInstallment::factory()->create([
            'clinic_id' => $clinic->id, 'payment_plan_id' => $plan->id,
            'sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => $half,
        ]),
        PaymentPlanInstallment::factory()->create([
            'clinic_id' => $clinic->id, 'payment_plan_id' => $plan->id,
            'sequence' => 2, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => $half,
        ]),
    ]);

    return compact('clinic', 'owner', 'patient', 'treatment', 'plan', 'installments');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pciPayload(array $overrides = []): array
{
    return array_merge(['payment_method' => 'cash'], $overrides);
}

// ---------------------------------------------------------------------------
// Happy path — collect a pending installment
// ---------------------------------------------------------------------------

it('collects a pending installment: creates a linked Completed transaction and flips it to Paid', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'plan' => $plan, 'installments' => $installments, 'clinic' => $clinic] = pciSetup(100.00, 2);
    $installment = $installments->first();

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), pciPayload(['payment_method' => 'card']))
        ->assertRedirect();

    $tx = Transaction::withoutGlobalScopes()
        ->where('payment_plan_installment_id', $installment->id)
        ->first();

    expect($tx)->not->toBeNull()
        ->and((float) $tx->amount)->toBe(100.0)
        ->and($tx->status)->toBe(TransactionStatus::Completed)
        ->and($tx->payment_method->value)->toBe('card')
        ->and($tx->patient_id)->toBe($patient->id)
        ->and($tx->treatment_id)->toBeNull()
        ->and($tx->clinic_id)->toBe($clinic->id);

    $installment->refresh();
    expect($installment->status)->toBe(InstallmentStatus::Paid)
        ->and($installment->paid_at)->not->toBeNull();
});

it('records a backdated paid_at identically on the transaction and the installment', function (): void {
    ['owner' => $owner, 'installments' => $installments] = pciSetup(100.00, 2);
    $installment = $installments->first();
    $backdated = now()->subDays(3)->startOfSecond();

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), pciPayload([
            'paid_at' => $backdated->toDateTimeString(),
        ]))
        ->assertRedirect();

    $tx = Transaction::withoutGlobalScopes()
        ->where('payment_plan_installment_id', $installment->id)
        ->first();

    $installment->refresh();

    expect($tx->paid_at->equalTo($backdated))->toBeTrue()
        ->and($installment->paid_at->equalTo($backdated))->toBeTrue()
        ->and($installment->paid_at->equalTo($tx->paid_at))->toBeTrue();
});

it('writes a pending → paid status_log for the collected installment', function (): void {
    ['owner' => $owner, 'installments' => $installments] = pciSetup(100.00, 2);
    $installment = $installments->first();

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), pciPayload());

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan_installment')
        ->where('loggable_id', $installment->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('pending')
        ->and($log->to_status)->toBe('paid')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('carries the plan\'s treatment_id onto the collected transaction for a treatment-bound plan', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'installments' => $installments] = pciTreatmentSetup(200.00);
    $installment = $installments->first();

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), pciPayload())
        ->assertRedirect();

    $tx = Transaction::withoutGlobalScopes()
        ->where('payment_plan_installment_id', $installment->id)
        ->first();

    expect($tx->treatment_id)->toBe($treatment->id);
});

// ---------------------------------------------------------------------------
// Plan auto-completion — all installments Paid
// ---------------------------------------------------------------------------

it('leaves the plan Active after collecting a non-final installment', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'installments' => $installments] = pciSetup(100.00, 2);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installments->first()), pciPayload());

    expect($plan->fresh()->status)->toBe(PaymentPlanStatus::Active);
});

it('auto-completes the plan once every installment is Paid', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'installments' => $installments] = pciSetup(100.00, 2);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installments->get(0)), pciPayload());
    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installments->get(1)), pciPayload());

    expect($plan->fresh()->status)->toBe(PaymentPlanStatus::Completed);
});

it('writes an active → completed status_log for the plan when the last installment is paid', function (): void {
    ['owner' => $owner, 'plan' => $plan, 'installments' => $installments] = pciSetup(100.00, 2);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installments->get(0)), pciPayload());
    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installments->get(1)), pciPayload());

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan')
        ->where('loggable_id', $plan->id)
        ->where('to_status', 'completed')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBe('active');
});

// ---------------------------------------------------------------------------
// Guard — an already-Paid/Cancelled installment cannot be re-collected
// ---------------------------------------------------------------------------

it('rejects collecting an installment that is already Paid', function (): void {
    ['owner' => $owner, 'installments' => $installments] = pciSetup(100.00, 2);
    $installment = $installments->first();
    $installment->update(['status' => InstallmentStatus::Paid, 'paid_at' => now()]);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), pciPayload())
        ->assertSessionHasErrors('installment');

    expect(
        Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installment->id)->count()
    )->toBe(0);
});

it('rejects collecting an installment that has been Cancelled', function (): void {
    ['owner' => $owner, 'installments' => $installments] = pciSetup(100.00, 2);
    $installment = $installments->first();
    $installment->update(['status' => InstallmentStatus::Cancelled]);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), pciPayload())
        ->assertSessionHasErrors('installment');
});

// ---------------------------------------------------------------------------
// Existing overpayment guard is reused by installment collection
// ---------------------------------------------------------------------------

it('rejects collecting an installment that would push the treatment past its total (overpayment guard reused)', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment, 'installments' => $installments, 'clinic' => $clinic] = pciTreatmentSetup(200.00);

    // A separate, direct payment of 150 already exists on the treatment (outside the plan).
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '150.00',
        'status' => TransactionStatus::Completed,
    ]);

    // Collecting the 100.00 installment would push paid to 250 > 200 total.
    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installments->first()), pciPayload())
        ->assertSessionHasErrors('amount');

    $installments->first()->refresh();
    expect($installments->first()->status)->toBe(InstallmentStatus::Pending);
});
