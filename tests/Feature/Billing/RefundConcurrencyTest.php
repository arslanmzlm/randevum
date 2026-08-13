<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Billing\Services\RefundService;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role (refund concurrency tests).
 */
function rfcRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic + owner + patient + treatment + a Completed payment transaction.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, treatment: Treatment, payment: Transaction}
 */
function rfcSetup(float $paymentAmount = 200.00): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rfcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

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
        'subtotal_amount' => $paymentAmount,
        'discount_amount' => 0,
        'total_amount' => $paymentAmount,
        'status' => TreatmentStatus::Completed,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    $payment = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => number_format($paymentAmount, 2, '.', ''),
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subHour(),
        'created_by' => $owner->id,
    ]);

    return compact('clinic', 'owner', 'patient', 'treatment', 'payment');
}

// ---------------------------------------------------------------------------
// The refund locks the original transaction row and re-reads the refunded total inside
// the open DB transaction (regression for the B1 code-hunt finding — see RefundService::refund)
// ---------------------------------------------------------------------------

it('locks the original transaction row and re-reads the refunded total inside the open DB transaction', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'payment' => $payment] = rfcSetup(200.00);
    app(ClinicContext::class)->set($clinic->id);

    // RefreshDatabase already holds the suite at transaction level 1 — compare against this
    // captured base, not against 0.
    $base = DB::transactionLevel();
    $levels = [];

    DB::listen(function ($query) use (&$levels): void {
        $sql = strtolower($query->sql);

        // The lockForUpdate() re-read of the original transaction by its own id
        // (TransactionRepository::lockForUpdate).
        if (str_contains($sql, 'from "transactions"') && str_contains($sql, '"id" = ?')) {
            $levels['locked_original'] = DB::transactionLevel();
        }

        // The refundedTotalFor() SUM query, scoped by original_transaction_id.
        if (str_contains($sql, 'sum(') && str_contains($sql, 'transactions') && str_contains($sql, 'original_transaction_id')) {
            $levels['refunded_sum'] = DB::transactionLevel();
        }
    });

    app(RefundService::class)->refund($payment, ['amount' => '50.00', 'reason' => 'Test'], $owner);

    expect($levels['locked_original'] ?? null)->not->toBeNull()
        ->and($levels['locked_original'])->toBeGreaterThan($base)
        ->and($levels['refunded_sum'] ?? null)->not->toBeNull()
        ->and($levels['refunded_sum'])->toBeGreaterThan($base);
});

// ---------------------------------------------------------------------------
// A repeated refund against a stale original instance is rejected once the first has
// already consumed the remaining
// ---------------------------------------------------------------------------

it('a repeated refund on a stale original instance is rejected once the first has consumed the remaining', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'payment' => $payment] = rfcSetup(200.00);
    app(ClinicContext::class)->set($clinic->id);

    // Two independent, stale copies of the same row — refund() always re-locks and re-reads
    // by id, so neither copy's in-memory state can leak a wrong "remaining" into the second call.
    $first = Transaction::find($payment->id);
    $second = Transaction::find($payment->id);

    app(RefundService::class)->refund($first, ['amount' => '150.00', 'reason' => 'First'], $owner);

    expect(fn () => app(RefundService::class)->refund($second, ['amount' => '100.00', 'reason' => 'Second'], $owner))
        ->toThrow(ValidationException::class);

    expect(Transaction::withoutGlobalScopes()->where('original_transaction_id', $payment->id)->count())->toBe(1)
        ->and((float) Transaction::withoutGlobalScopes()->where('original_transaction_id', $payment->id)->sum('amount'))->toBe(-150.0)
        ->and($payment->fresh()->status)->toBe(TransactionStatus::PartiallyRefunded);
});

// ---------------------------------------------------------------------------
// The same race, exercised over HTTP — two requests submitted with amounts that were each
// individually ≤ the original amount (so the FormRequest passes both), before either had
// committed. The first refund settles; the second now exceeds the shrunk remaining →
// the service's remaining-cap guard rejects it.
// ---------------------------------------------------------------------------

it('a double-submitted HTTP refund rejects the second POST once the remaining has shrunk under it', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfcSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), ['amount' => '150.00', 'reason' => 'First'])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), ['amount' => '100.00', 'reason' => 'Second'])
        ->assertSessionHasErrors('amount');

    expect(Transaction::withoutGlobalScopes()->where('original_transaction_id', $payment->id)->count())->toBe(1)
        ->and($payment->fresh()->status)->toBe(TransactionStatus::PartiallyRefunded);
});
