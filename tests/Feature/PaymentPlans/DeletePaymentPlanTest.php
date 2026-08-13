<?php

use App\Enums\InstallmentStatus;
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

function dpplRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A plan with two pending installments — the mis-entered plan a delete is for.
 *
 * @return array{clinic: Clinic, owner: User, plan: PaymentPlan}
 */
function dpplSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    dpplRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'installment_count' => 2,
        'total_amount' => '200.00',
    ]);

    foreach ([1, 2] as $sequence) {
        PaymentPlanInstallment::factory()->create([
            'clinic_id' => $clinic->id,
            'payment_plan_id' => $plan->id,
            'sequence' => $sequence,
            'amount' => '100.00',
            'status' => InstallmentStatus::Pending,
        ]);
    }

    return ['clinic' => $clinic, 'owner' => $owner, 'plan' => $plan];
}

it('deletes an untouched plan with its installments and status logs', function (): void {
    ['owner' => $owner, 'plan' => $plan] = dpplSetup();

    $this->actingAs($owner)
        ->delete(route('payment-plans.destroy', $plan))
        ->assertRedirect();

    expect(PaymentPlan::withoutGlobalScopes()->find($plan->id))->toBeNull()
        ->and(PaymentPlanInstallment::withoutGlobalScopes()->where('payment_plan_id', $plan->id)->count())->toBe(0)
        ->and(StatusLog::withoutGlobalScopes()->where('loggable_type', 'payment_plan')->where('loggable_id', $plan->id)->count())->toBe(0);
});

it('refuses to delete a plan once an installment has been collected', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'plan' => $plan] = dpplSetup();

    // No active clinic outside the request, and ClinicScope is fail-closed.
    $installment = $plan->installments()->withoutGlobalScopes()->first();
    $installment->forceFill([
        'status' => InstallmentStatus::Paid,
        'paid_at' => now(),
    ])->save();

    $this->actingAs($owner)
        ->delete(route('payment-plans.destroy', $plan))
        ->assertForbidden();

    expect(PaymentPlan::withoutGlobalScopes()->find($plan->id))->not->toBeNull();
});

it('refuses to delete a plan that has a transaction against an installment', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'plan' => $plan] = dpplSetup();

    // No active clinic outside the request, and ClinicScope is fail-closed.
    $installment = $plan->installments()->withoutGlobalScopes()->first();

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $plan->patient_id,
        'payment_plan_installment_id' => $installment->id,
        'amount' => '100.00',
    ]);

    $this->actingAs($owner)
        ->delete(route('payment-plans.destroy', $plan))
        ->assertForbidden();

    expect(PaymentPlan::withoutGlobalScopes()->find($plan->id))->not->toBeNull();
});

it('forbids roles without the delete permission', function (string $role): void {
    ['clinic' => $clinic, 'plan' => $plan] = dpplSetup();

    $user = User::factory()->create();
    dpplRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->delete(route('payment-plans.destroy', $plan))
        ->assertForbidden();

    expect(PaymentPlan::withoutGlobalScopes()->find($plan->id))->not->toBeNull();
})->with(['doctor', 'receptionist', 'assistant']);

it("never deletes another clinic's plan", function (): void {
    ['plan' => $plan] = dpplSetup();

    $otherClinic = Clinic::factory()->create();
    $otherOwner = User::factory()->create();
    dpplRole($otherOwner, 'owner', $otherClinic->id);

    $this->actingAs($otherOwner)
        ->delete(route('payment-plans.destroy', $plan))
        ->assertNotFound();

    expect(PaymentPlan::withoutGlobalScopes()->find($plan->id))->not->toBeNull();
});
