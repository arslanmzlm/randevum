<?php

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role (payment-plan tenant-isolation tests).
 */
function pplTiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Two independent clinics, each with its own owner + patient + a 1-installment plan.
 *
 * @return array{
 *   clinicA: Clinic, ownerA: User, patientA: Patient, planA: PaymentPlan, installmentA: PaymentPlanInstallment,
 *   clinicB: Clinic, patientB: Patient, planB: PaymentPlan, installmentB: PaymentPlanInstallment,
 * }
 */
function pplTiTwoClinicFixture(): array
{
    $clinicA = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    pplTiRole($ownerA, 'owner', $clinicA->id);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $planA = PaymentPlan::factory()->create([
        'clinic_id' => $clinicA->id, 'patient_id' => $patientA->id, 'total_amount' => '100.00', 'installment_count' => 1,
    ]);
    $installmentA = PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinicA->id, 'payment_plan_id' => $planA->id, 'sequence' => 1,
        'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00',
    ]);

    $clinicB = Clinic::factory()->create();
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $planB = PaymentPlan::factory()->create([
        'clinic_id' => $clinicB->id, 'patient_id' => $patientB->id, 'total_amount' => '100.00', 'installment_count' => 1,
    ]);
    $installmentB = PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinicB->id, 'payment_plan_id' => $planB->id, 'sequence' => 1,
        'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00',
    ]);

    return compact('clinicA', 'ownerA', 'patientA', 'planA', 'installmentA', 'clinicB', 'patientB', 'planB', 'installmentB');
}

// ---------------------------------------------------------------------------
// Collect — cross-clinic 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 collecting a clinic B installment (scoped route binding)', function (): void {
    ['ownerA' => $ownerA, 'installmentB' => $installmentB] = pplTiTwoClinicFixture();

    $this->actingAs($ownerA)
        ->post(route('payment-plans.installments.collect', $installmentB), ['payment_method' => 'cash'])
        ->assertNotFound();

    expect(
        Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installmentB->id)->exists()
    )->toBeFalse();

    expect($installmentB->fresh()->status)->toBe(InstallmentStatus::Pending);
});

it('clinic A owner gets 404 partially collecting a clinic B installment (scoped route binding)', function (): void {
    ['ownerA' => $ownerA, 'installmentB' => $installmentB] = pplTiTwoClinicFixture();

    $this->actingAs($ownerA)
        ->post(route('payment-plans.installments.collect', $installmentB), ['payment_method' => 'cash', 'amount' => '30.00'])
        ->assertNotFound();

    expect(
        Transaction::withoutGlobalScopes()->where('payment_plan_installment_id', $installmentB->id)->exists()
    )->toBeFalse();

    expect($installmentB->fresh()->status)->toBe(InstallmentStatus::Pending);
});

// ---------------------------------------------------------------------------
// Cancel — cross-clinic 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 cancelling a clinic B payment plan (scoped route binding)', function (): void {
    ['ownerA' => $ownerA, 'planB' => $planB] = pplTiTwoClinicFixture();

    $this->actingAs($ownerA)
        ->post(route('payment-plans.cancel', $planB))
        ->assertNotFound();

    expect($planB->fresh()->status)->toBe(PaymentPlanStatus::Active);
});

// ---------------------------------------------------------------------------
// Manual remind — cross-clinic 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 sending a manual reminder for a clinic B installment', function (): void {
    Queue::fake();
    ['ownerA' => $ownerA, 'installmentB' => $installmentB] = pplTiTwoClinicFixture();

    $this->actingAs($ownerA)
        ->post(route('payment-plans.installments.remind', $installmentB))
        ->assertNotFound();

    Queue::assertNotPushed(SendSmsJob::class);
});

// ---------------------------------------------------------------------------
// Collections index — read isolation
// ---------------------------------------------------------------------------

it("clinic A's pending-installments screen never exposes clinic B's installments", function (): void {
    ['ownerA' => $ownerA, 'installmentA' => $installmentA] = pplTiTwoClinicFixture();

    $this->actingAs($ownerA)
        ->get(route('payment-plans.installments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('installments', 1)
            ->where('installments.0.id', $installmentA->id)
        );
});

// ---------------------------------------------------------------------------
// Create — cross-clinic exists-rule validation
// ---------------------------------------------------------------------------

it('rejects a clinic B patient_id in a clinic A store request (exists rule scoped to active clinic)', function (): void {
    ['ownerA' => $ownerA, 'patientB' => $patientB] = pplTiTwoClinicFixture();

    $this->actingAs($ownerA)
        ->post(route('payment-plans.store'), [
            'patient_id' => $patientB->id,
            'treatment_id' => null,
            'total_amount' => '100.00',
            'down_payment' => null,
            'installment_count' => 1,
            'installments' => [
                ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00'],
            ],
        ])
        ->assertSessionHasErrors('patient_id');

    expect(
        PaymentPlan::withoutGlobalScopes()->where('patient_id', $patientB->id)->count()
    )->toBe(1); // only the fixture's own plan for patient B — none created by clinic A's request
});

it('rejects a clinic B treatment_id in a clinic A store request (exists rule scoped to active clinic)', function (): void {
    ['ownerA' => $ownerA, 'patientA' => $patientA, 'clinicB' => $clinicB] = pplTiTwoClinicFixture();

    $treatmentB = Treatment::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->post(route('payment-plans.store'), [
            'patient_id' => $patientA->id,
            'treatment_id' => $treatmentB->id,
            'total_amount' => '100.00',
            'down_payment' => null,
            'installment_count' => 1,
            'installments' => [
                ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00'],
            ],
        ])
        ->assertSessionHasErrors('treatment_id');

    expect(
        PaymentPlan::withoutGlobalScopes()->where('treatment_id', $treatmentB->id)->exists()
    )->toBeFalse();
});
