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
 * Assign a clinic-scoped role (deleted-patient installment tests).
 */
function idpRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A clinic + owner + patient holding one pending installment.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, installment: PaymentPlanInstallment}
 */
function idpSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    idpRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Zeynep',
        'last_name' => 'Kaya',
    ]);

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

    return compact('clinic', 'owner', 'patient', 'installment');
}

it('the collections screen flags patient_is_deleted false for a live patient', function (): void {
    ['owner' => $owner, 'installment' => $installment] = idpSetup();

    $this->actingAs($owner)
        ->get(route('payment-plans.installments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('installments', 1)
            ->where('installments.0.id', $installment->id)
            ->where('installments.0.patient_is_deleted', false)
        );
});

it('the collections screen keeps the receivable and its patient name after the patient is soft-deleted', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'installment' => $installment] = idpSetup();

    $patient->delete();

    $this->actingAs($owner)
        ->get(route('payment-plans.installments'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('installments', 1)
            ->where('installments.0.id', $installment->id)
            ->where('installments.0.patient_name', 'Zeynep Kaya')
            ->where('installments.0.patient_is_deleted', true)
        );
});

it("does not surface another clinic's soft-deleted patient on the collections screen", function (): void {
    ['owner' => $ownerA] = idpSetup();

    $clinicB = Clinic::factory()->create();
    $patientB = Patient::factory()->create([
        'clinic_id' => $clinicB->id,
        'first_name' => 'SilinenGizli',
        'last_name' => 'GizliSoyadXyz',
    ]);

    $planB = PaymentPlan::factory()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'total_amount' => '100.00',
        'installment_count' => 1,
    ]);

    PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinicB->id,
        'payment_plan_id' => $planB->id,
        'sequence' => 1,
        'due_date' => now()->addMonth()->toDateString(),
        'amount' => '100.00',
        'status' => InstallmentStatus::Pending,
    ]);

    $patientB->delete();

    $response = $this->actingAs($ownerA)->get(route('payment-plans.installments'));

    $response->assertOk()
        ->assertInertia(fn ($page) => $page->has('installments', 1));

    expect($response->getContent())->not->toContain('GizliSoyadXyz');
});
