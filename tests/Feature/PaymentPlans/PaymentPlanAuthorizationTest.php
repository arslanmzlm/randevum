<?php

use App\Enums\InstallmentStatus;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
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

/**
 * Assign a clinic-scoped role (payment-plan authorization tests).
 */
function ppaRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinic: Clinic, patient: Patient}
 */
function ppaClinic(): array
{
    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'patient');
}

/**
 * @return array{clinic: Clinic, patient: Patient, plan: PaymentPlan, installment: PaymentPlanInstallment}
 */
function ppaPlanWithInstallment(): array
{
    ['clinic' => $clinic, 'patient' => $patient] = ppaClinic();

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'total_amount' => '100.00',
        'installment_count' => 1,
    ]);

    $installment = PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinic->id,
        'payment_plan_id' => $plan->id,
        'sequence' => 1,
        'due_date' => now()->addMonth()->toDateString(),
        'amount' => '100.00',
        'status' => InstallmentStatus::Pending,
    ]);

    return compact('clinic', 'patient', 'plan', 'installment');
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ppaCreatePayload(int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'treatment_id' => null,
        'total_amount' => '100.00',
        'down_payment' => null,
        'installment_count' => 1,
        'installments' => [
            ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00'],
        ],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// viewAny — GET /payment-plans/installments (owner/manager/receptionist)
// ---------------------------------------------------------------------------

it('allows owner/manager/receptionist to view the pending-installments screen', function (string $role): void {
    ['clinic' => $clinic] = ppaClinic();
    $user = User::factory()->create();
    ppaRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->get(route('payment-plans.installments'))
        ->assertOk();
})->with(['owner', 'manager', 'receptionist']);

it('denies doctor/assistant the pending-installments screen (lacks paymentPlans.viewAny)', function (string $role): void {
    ['clinic' => $clinic] = ppaClinic();
    $user = User::factory()->create();
    ppaRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->get(route('payment-plans.installments'))
        ->assertForbidden();
})->with(['doctor', 'assistant']);

// ---------------------------------------------------------------------------
// create — POST /payment-plans (owner/manager/doctor/receptionist)
// ---------------------------------------------------------------------------

it('allows owner/manager/doctor/receptionist to create a payment plan', function (string $role): void {
    ['clinic' => $clinic, 'patient' => $patient] = ppaClinic();
    $user = User::factory()->create();
    ppaRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('payment-plans.store'), ppaCreatePayload($patient->id))
        ->assertRedirect();
})->with(['owner', 'manager', 'doctor', 'receptionist']);

it('denies assistant creating a payment plan (lacks paymentPlans.create)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = ppaClinic();
    $assistant = User::factory()->create();
    ppaRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('payment-plans.store'), ppaCreatePayload($patient->id))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// cancel — POST /payment-plans/{plan}/cancel (owner/manager only)
// ---------------------------------------------------------------------------

it('allows owner/manager to cancel a payment plan', function (string $role): void {
    ['clinic' => $clinic, 'plan' => $plan] = ppaPlanWithInstallment();
    $user = User::factory()->create();
    ppaRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('payment-plans.cancel', $plan))
        ->assertRedirect();
})->with(['owner', 'manager']);

it('denies doctor/receptionist/assistant cancelling a payment plan (lacks paymentPlans.cancel)', function (string $role): void {
    ['clinic' => $clinic, 'plan' => $plan] = ppaPlanWithInstallment();
    $user = User::factory()->create();
    ppaRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('payment-plans.cancel', $plan))
        ->assertForbidden();
})->with(['doctor', 'receptionist', 'assistant']);

// ---------------------------------------------------------------------------
// sendReminder — POST /payment-plans/installments/{installment}/remind (owner/manager/receptionist)
// ---------------------------------------------------------------------------

it('allows owner/manager/receptionist to send a manual installment reminder', function (string $role): void {
    ['clinic' => $clinic, 'installment' => $installment] = ppaPlanWithInstallment();
    $user = User::factory()->create();
    ppaRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('payment-plans.installments.remind', $installment))
        ->assertRedirect();
})->with(['owner', 'manager', 'receptionist']);

it('denies doctor/assistant sending a manual installment reminder (lacks paymentPlans.sendReminder)', function (string $role): void {
    ['clinic' => $clinic, 'installment' => $installment] = ppaPlanWithInstallment();
    $user = User::factory()->create();
    ppaRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('payment-plans.installments.remind', $installment))
        ->assertForbidden();
})->with(['doctor', 'assistant']);

// ---------------------------------------------------------------------------
// collect — POST /payment-plans/installments/{installment}/collect (transactions.create)
// ---------------------------------------------------------------------------

it('allows owner/manager/doctor/receptionist to collect an installment (transactions.create)', function (string $role): void {
    ['clinic' => $clinic, 'installment' => $installment] = ppaPlanWithInstallment();
    $user = User::factory()->create();
    ppaRole($user, $role, $clinic->id);

    $this->actingAs($user)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash'])
        ->assertRedirect();
})->with(['owner', 'manager', 'doctor', 'receptionist']);

it('denies assistant collecting an installment (lacks transactions.create)', function (): void {
    ['clinic' => $clinic, 'installment' => $installment] = ppaPlanWithInstallment();
    $assistant = User::factory()->create();
    ppaRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('payment-plans.installments.collect', $installment), ['payment_method' => 'cash'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Unauthenticated — redirected to login
// ---------------------------------------------------------------------------

it('redirects a guest to login from the pending-installments screen', function (): void {
    $this->get(route('payment-plans.installments'))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Inertia prop contract — UI gating proven at the HTTP layer
// ---------------------------------------------------------------------------

it('includes all four paymentPlans permissions in auth.permissions for owner', function (): void {
    ['clinic' => $clinic] = ppaClinic();
    $owner = User::factory()->create();
    ppaRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => $p->contains('paymentPlans.viewAny')
                && $p->contains('paymentPlans.create')
                && $p->contains('paymentPlans.cancel')
                && $p->contains('paymentPlans.sendReminder'),
        ));
});

it('excludes every paymentPlans permission from auth.permissions for assistant', function (): void {
    ['clinic' => $clinic] = ppaClinic();
    $assistant = User::factory()->create();
    ppaRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => ! $p->contains('paymentPlans.viewAny')
                && ! $p->contains('paymentPlans.create')
                && ! $p->contains('paymentPlans.cancel')
                && ! $p->contains('paymentPlans.sendReminder'),
        ));
});

// ---------------------------------------------------------------------------
// Inertia prop contract — collections screen data shape + overdue derivation
// ---------------------------------------------------------------------------

it('the collections screen sends the installments prop shape with a derived is_overdue flag', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = ppaClinic();
    $owner = User::factory()->create();
    ppaRole($owner, 'owner', $clinic->id);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'total_amount' => '100.00',
        'installment_count' => 1,
    ]);

    $overdueInstallment = PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinic->id,
        'payment_plan_id' => $plan->id,
        'sequence' => 1,
        'due_date' => now()->subDays(3)->toDateString(),
        'amount' => '100.00',
        'status' => InstallmentStatus::Pending,
    ]);

    $this->actingAs($owner)
        ->get(route('payment-plans.installments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('payment-plans/Index')
            ->has('installments', 1)
            ->where('installments.0.id', $overdueInstallment->id)
            ->where('installments.0.plan_id', $plan->id)
            ->where('installments.0.patient_id', $patient->id)
            ->where('installments.0.status', InstallmentStatus::Pending->value)
            ->where('installments.0.is_overdue', true)
            ->has('stats.overdue_count')
            ->has('stats.overdue_total')
            ->has('stats.due_soon_count')
            ->has('stats.due_soon_total')
        );
});
