<?php

use App\Enums\InstallmentStatus;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Billing\Services\PaymentPlanService;
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

function cicRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A single installment on a patient-general plan (treatment_id null).
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, plan: PaymentPlan, installment: PaymentPlanInstallment}
 */
function cicSetup(float $amount = 300.00): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cicRole($owner, 'owner', $clinic->id);
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
// The remaining is derived — and the installment re-read — inside the DB transaction
// ---------------------------------------------------------------------------

it('derives the remaining and re-reads the locked installment inside the open DB transaction', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'installment' => $installment] = cicSetup(300.00);
    app(ClinicContext::class)->set($clinic->id);

    // RefreshDatabase already holds the suite at transaction level 1 — compare against this
    // captured base, not against 0.
    $base = DB::transactionLevel();
    $levels = [];

    DB::listen(function ($query) use (&$levels): void {
        $sql = strtolower($query->sql);

        // The collectedTotal() SUM query against transactions, scoped by the installment FK.
        if (str_contains($sql, 'sum(') && str_contains($sql, 'transactions') && str_contains($sql, 'payment_plan_installment_id')) {
            $levels['remaining_sum'] = DB::transactionLevel();
        }

        // The lockForUpdate() re-read of the installment by its own id (not the allPaid()/
        // pendingStats() queries, which filter on payment_plan_id instead).
        if (
            str_contains($sql, 'payment_plan_installments')
            && str_contains($sql, '"id" = ?')
            && ! str_contains($sql, 'payment_plan_id')
        ) {
            $levels['locked_reread'] = DB::transactionLevel();
        }
    });

    app(PaymentPlanService::class)->collect($installment, ['payment_method' => 'cash', 'amount' => '100.00'], $owner);

    expect($levels['remaining_sum'] ?? null)->not->toBeNull()
        ->and($levels['remaining_sum'])->toBeGreaterThan($base)
        ->and($levels['locked_reread'] ?? null)->not->toBeNull()
        ->and($levels['locked_reread'])->toBeGreaterThan($base);
});

// ---------------------------------------------------------------------------
// A second collect against the same installment id is rejected once the first is settled
// ---------------------------------------------------------------------------

it('a repeated full collect on a stale installment instance is rejected once the first has settled the installment', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'installment' => $installment] = cicSetup(300.00);
    app(ClinicContext::class)->set($clinic->id);

    // Two independent, stale copies of the same row — collect() always re-reads by id under
    // a lock, so neither copy's in-memory state can leak a wrong "remaining" into the second call.
    $first = PaymentPlanInstallment::find($installment->id);
    $second = PaymentPlanInstallment::find($installment->id);

    app(PaymentPlanService::class)->collect($first, ['payment_method' => 'cash', 'amount' => '300.00'], $owner);

    expect(fn () => app(PaymentPlanService::class)->collect($second, ['payment_method' => 'cash', 'amount' => '300.00'], $owner))
        ->toThrow(ValidationException::class);

    expect(Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installment->id)->count())->toBe(1)
        ->and((float) Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installment->id)->sum('amount'))->toBe(300.0)
        ->and($installment->fresh()->status)->toBe(InstallmentStatus::Paid);
});

// ---------------------------------------------------------------------------
// The same race, exercised over HTTP — two requests submitted with the same (now-stale)
// amount before either had committed. The first collects; the second's amount, valid at
// the time it was typed, now exceeds the shrunk remaining → the amount guard rejects it.
// ---------------------------------------------------------------------------

it('a double-submitted HTTP collect rejects the second POST once the remaining has shrunk under it', function (): void {
    ['owner' => $owner, 'installment' => $installment] = cicSetup(300.00);

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '200.00'])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash', 'amount' => '200.00'])
        ->assertSessionHasErrors('amount');

    expect(Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installment->id)->count())->toBe(1)
        ->and($installment->fresh()->status)->toBe(InstallmentStatus::PartiallyPaid);
});
