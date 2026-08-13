<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Expense;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
    Cache::flush();

    // Same fixed "now" as FinanceReportTest: 09:00 UTC is 12:00 Istanbul, so the
    // clinic-local calendar day the summary cards report is unambiguous.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function rciRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinic: Clinic, owner: User, patient: Patient}
 */
function rciSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rciRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'patient');
}

/**
 * A settled collection written straight through the factory, so it bypasses the services
 * that dispatch ClinicFinancesChanged. This is the "invisible" row the assertions use: it
 * only shows up in the report once something ELSE drops the cache.
 */
function rciRawPayment(Clinic $clinic, ?Patient $patient, string $amount): Transaction
{
    return Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient?->id,
        'amount' => $amount,
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => CarbonImmutable::parse('2026-06-15 09:30:00', 'UTC'),
    ]);
}

/** Read the finance report and assert today's revenue card. */
function rciExpectToday(User $owner, string $expected): void
{
    test()->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('revenue.summary.today', $expected));
}

/**
 * A completed treatment whose payment was fully refunded (net zero, so it is voidable)
 * plus the owner able to void it.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, treatment: Treatment}
 */
function rciRefundedTreatment(string $amount = '100.00'): array
{
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rciSetup();

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => '2026-06-15 06:00:00',
    ]);

    $treatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'subtotal_amount' => $amount,
        'discount_amount' => '0.00',
        'total_amount' => $amount,
        'completed_at' => '2026-06-15 07:00:00',
    ]);

    $payment = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => $amount,
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Refunded,
        'paid_at' => CarbonImmutable::parse('2026-06-15 08:00:00', 'UTC'),
    ]);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'original_transaction_id' => $payment->id,
        'amount' => '-'.$amount,
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => CarbonImmutable::parse('2026-06-15 08:30:00', 'UTC'),
    ]);

    return compact('clinic', 'owner', 'patient', 'treatment');
}

// ---------------------------------------------------------------------------
// The cache still caches — invalidation must not degrade into "never cache"
// ---------------------------------------------------------------------------

it('serves the second consecutive read from cache without re-querying transactions', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rciSetup();
    rciRawPayment($clinic, $patient, '100.00');

    rciExpectToday($owner, '100.00');

    $transactionQueries = 0;
    DB::listen(function ($query) use (&$transactionQueries): void {
        if (str_contains($query->sql, 'transactions')) {
            $transactionQueries++;
        }
    });

    rciExpectToday($owner, '100.00');

    expect($transactionQueries)->toBe(0);
});

// ---------------------------------------------------------------------------
// Collections
// ---------------------------------------------------------------------------

it('drops the cache when a payment is recorded', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rciSetup();
    rciRawPayment($clinic, $patient, '100.00');

    rciExpectToday($owner, '100.00');

    $this->actingAs($owner)
        ->post(route('payments.store'), [
            'patient_id' => $patient->id,
            'treatment_id' => null,
            'amount' => '50.00',
            'payment_method' => 'cash',
        ])
        ->assertRedirect();

    rciExpectToday($owner, '150.00');
});

it('drops the cache when manual income is recorded', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rciSetup();
    rciRawPayment($clinic, $patient, '100.00');

    rciExpectToday($owner, '100.00');

    $this->actingAs($owner)
        ->post(route('incomes.store'), [
            'paid_at' => '2026-06-15 09:00:00',
            'amount' => '40.00',
            'payment_method' => 'cash',
            'category' => 'Kira geliri',
        ])
        ->assertRedirect();

    rciExpectToday($owner, '140.00');
});

it('drops the cache when a manual income row is deleted', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rciSetup();

    $income = Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'amount' => '80.00',
        'paid_at' => CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'),
    ]);

    rciExpectToday($owner, '80.00');

    $this->actingAs($owner)
        ->delete(route('incomes.destroy', $income))
        ->assertRedirect();

    rciExpectToday($owner, '0.00');
});

it('drops the cache when an installment is collected', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rciSetup();
    rciRawPayment($clinic, $patient, '100.00');

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'total_amount' => '120.00',
        'down_payment' => null,
        'installment_count' => 2,
    ]);

    $installment = PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinic->id,
        'payment_plan_id' => $plan->id,
        'sequence' => 1,
        'due_date' => '2026-07-15',
        'amount' => '60.00',
    ]);

    rciExpectToday($owner, '100.00');

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash'])
        ->assertRedirect();

    rciExpectToday($owner, '160.00');
});

// ---------------------------------------------------------------------------
// Refunds
// ---------------------------------------------------------------------------

it('drops the cache when a payment is refunded', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rciSetup();
    $payment = rciRawPayment($clinic, $patient, '100.00');

    rciExpectToday($owner, '100.00');

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), [
            'amount' => '30.00',
            'reason' => 'Yanlış tahsilat',
        ])
        ->assertRedirect();

    rciExpectToday($owner, '70.00');
});

// ---------------------------------------------------------------------------
// Treatment void
// ---------------------------------------------------------------------------

it('drops the cache when a treatment is voided', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'treatment' => $treatment] = rciRefundedTreatment('100.00');

    // The voided treatment's payment + counter-entry net to zero, so the visible move is
    // the unrelated row that was cached alongside them.
    rciRawPayment($clinic, null, '25.00');

    rciExpectToday($owner, '25.00');

    // Cached: a row added now stays invisible until something drops the cache.
    rciRawPayment($clinic, null, '10.00');
    rciExpectToday($owner, '25.00');

    $this->actingAs($owner)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);

    rciExpectToday($owner, '35.00');
});

// ---------------------------------------------------------------------------
// Expenses
// ---------------------------------------------------------------------------

it('drops the cache when an expense is recorded, updated and deleted', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rciSetup();
    rciRawPayment($clinic, $patient, '100.00');

    rciExpectToday($owner, '100.00');

    // A row that only becomes visible once the expense movement drops the cache.
    rciRawPayment($clinic, $patient, '10.00');

    $this->actingAs($owner)
        ->post(route('expenses.store'), [
            'expense_date' => '2026-06-15',
            'amount' => '20.00',
            'category' => 'Kira',
        ])
        ->assertRedirect();

    rciExpectToday($owner, '110.00');

    $expense = Expense::withoutGlobalScopes()->where('clinic_id', $clinic->id)->firstOrFail();

    rciRawPayment($clinic, $patient, '5.00');

    $this->actingAs($owner)
        ->put(route('expenses.update', $expense), [
            'expense_date' => '2026-06-15',
            'amount' => '35.00',
            'category' => 'Kira',
        ])
        ->assertRedirect();

    rciExpectToday($owner, '115.00');

    rciRawPayment($clinic, $patient, '2.00');

    $this->actingAs($owner)
        ->delete(route('expenses.destroy', $expense))
        ->assertRedirect();

    rciExpectToday($owner, '117.00');
});

// ---------------------------------------------------------------------------
// Tenant isolation — the cache key is per clinic, so only the moved clinic is dropped
// ---------------------------------------------------------------------------

it('never drops another clinic\'s cache when money moves', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rciSetup();
    rciRawPayment($clinic, $patient, '100.00');

    ['clinic' => $otherClinic, 'owner' => $otherOwner, 'patient' => $otherPatient] = rciSetup();
    rciRawPayment($otherClinic, $otherPatient, '700.00');

    rciExpectToday($owner, '100.00');
    rciExpectToday($otherOwner, '700.00');

    // Both clinics gain an invisible row; only the second one moves money.
    rciRawPayment($clinic, $patient, '11.00');
    rciRawPayment($otherClinic, $otherPatient, '22.00');

    $this->actingAs($otherOwner)
        ->post(route('payments.store'), [
            'patient_id' => $otherPatient->id,
            'treatment_id' => null,
            'amount' => '3.00',
            'payment_method' => 'cash',
        ])
        ->assertRedirect();

    // The moving clinic recomputed (700 + 22 + 3); the untouched one still serves its cache.
    rciExpectToday($otherOwner, '725.00');
    rciExpectToday($owner, '100.00');
});
