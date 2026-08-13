<?php

use App\Enums\AppointmentStatus;
use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\Service;
use App\Models\StatusLog;
use App\Models\Transaction;
use App\Models\Treatment;
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
 * Assign a clinic-scoped role (create-payment-plan tests).
 */
function cppRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic + owner + patient, ready for a standalone payment-plan create.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient}
 */
function cppSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cppRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'patient');
}

/**
 * Minimal valid POST /payment-plans payload: 3 equal installments, no down payment.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cppPayload(int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'treatment_id' => null,
        'total_amount' => '300.00',
        'down_payment' => null,
        'installment_count' => 3,
        'installments' => [
            ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00'],
            ['sequence' => 2, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => '100.00'],
            ['sequence' => 3, 'due_date' => now()->addMonths(3)->toDateString(), 'amount' => '100.00'],
        ],
    ], $overrides);
}

/**
 * Build a Draft treatment (Arrived appointment) with a single service line so its computed
 * total is controllable for the treatment-Process installment entry point.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, doctor: Doctor, treatment: Treatment}
 */
function cppTreatmentSetup(float $servicePrice = 200.00): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cppRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = now()->subHour();
    $appointment = Appointment::factory()
        ->withStatus(AppointmentStatus::Arrived)
        ->create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctor->id,
            'patient_id' => $patient->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
        ]);

    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Draft,
        'created_by' => $owner->id,
    ]);

    Service::factory()->create(['clinic_id' => $clinic->id, 'price' => number_format($servicePrice, 2, '.', '')]);

    return compact('clinic', 'owner', 'patient', 'doctor', 'treatment');
}

// ---------------------------------------------------------------------------
// Standalone create (patient detail) — happy path
// ---------------------------------------------------------------------------

it('creates a patient-general payment plan with N installments (treatment_id null)', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'clinic' => $clinic] = cppSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id))
        ->assertRedirect();

    $plan = PaymentPlan::withoutGlobalScopes()->where('patient_id', $patient->id)->first();

    expect($plan)->not->toBeNull()
        ->and($plan->treatment_id)->toBeNull()
        ->and($plan->clinic_id)->toBe($clinic->id)
        ->and($plan->status)->toBe(PaymentPlanStatus::Active)
        ->and((float) $plan->total_amount)->toBe(300.0)
        ->and($plan->installment_count)->toBe(3)
        ->and($plan->created_by)->toBe($owner->id);

    $installments = $plan->installments()->orderBy('sequence')->get();

    expect($installments)->toHaveCount(3);
    foreach ($installments as $i => $installment) {
        expect($installment->sequence)->toBe($i + 1)
            ->and((float) $installment->amount)->toBe(100.0)
            ->and($installment->status)->toBe(InstallmentStatus::Pending)
            ->and($installment->clinic_id)->toBe($clinic->id);
    }
});

it('writes a null → active status_log for the created plan', function (): void {
    ['owner' => $owner, 'patient' => $patient] = cppSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id));

    $plan = PaymentPlan::withoutGlobalScopes()->where('patient_id', $patient->id)->first();

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'payment_plan')
        ->where('loggable_id', $plan->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBeNull()
        ->and($log->to_status)->toBe('active')
        ->and($log->by_user_id)->toBe($owner->id);
});

// ---------------------------------------------------------------------------
// Down payment = immediate collection, not linked to any installment
// ---------------------------------------------------------------------------

it('collects the down payment immediately as a transaction not linked to any installment', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'clinic' => $clinic] = cppSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id, [
            'total_amount' => '300.00',
            'down_payment' => '60.00',
            'down_payment_method' => 'cash',
            'installment_count' => 3,
            'installments' => [
                ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '80.00'],
                ['sequence' => 2, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => '80.00'],
                ['sequence' => 3, 'due_date' => now()->addMonths(3)->toDateString(), 'amount' => '80.00'],
            ],
        ]))
        ->assertRedirect();

    $tx = Transaction::withoutGlobalScopes()->where('patient_id', $patient->id)->first();

    expect($tx)->not->toBeNull()
        ->and((float) $tx->amount)->toBe(60.0)
        ->and($tx->payment_plan_installment_id)->toBeNull()
        ->and($tx->treatment_id)->toBeNull()
        ->and($tx->clinic_id)->toBe($clinic->id)
        ->and(Transaction::withoutGlobalScopes()->where('patient_id', $patient->id)->count())->toBe(1);

    // No installment was marked paid by the down payment.
    $plan = PaymentPlan::withoutGlobalScopes()->where('patient_id', $patient->id)->first();
    expect($plan->installments()->where('status', InstallmentStatus::Pending->value)->count())->toBe(3);
});

it('does not record a down payment transaction when down_payment is zero/absent', function (): void {
    ['owner' => $owner, 'patient' => $patient] = cppSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id));

    expect(Transaction::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Server-side sum-check
// ---------------------------------------------------------------------------

it('rejects a plan whose installment sum + down payment does not equal total_amount', function (): void {
    ['owner' => $owner, 'patient' => $patient] = cppSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id, [
            'total_amount' => '300.00',
            'installments' => [
                ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00'],
                ['sequence' => 2, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => '100.00'],
                // Missing the third 100 — sums to 200, not 300.
            ],
            'installment_count' => 2,
        ]))
        ->assertSessionHasErrors('installments');

    expect(PaymentPlan::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

it('rejects a plan whose installment sequences are not contiguous 1..N', function (): void {
    ['owner' => $owner, 'patient' => $patient] = cppSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id, [
            'total_amount' => '300.00',
            'installment_count' => 3,
            'installments' => [
                ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00'],
                ['sequence' => 3, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => '100.00'],
                ['sequence' => 4, 'due_date' => now()->addMonths(3)->toDateString(), 'amount' => '100.00'],
            ],
        ]))
        ->assertSessionHasErrors('installments');

    expect(PaymentPlan::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Deleted / foreign patients
// ---------------------------------------------------------------------------

it('rejects a plan opened for a soft-deleted patient', function (): void {
    ['owner' => $owner, 'patient' => $patient] = cppSetup();

    $patient->delete();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id))
        ->assertSessionHasErrors('patient_id');

    expect(PaymentPlan::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeFalse();
});

it('accepts a plan for the same patient while the record is live', function (): void {
    ['owner' => $owner, 'patient' => $patient] = cppSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id))
        ->assertSessionHasNoErrors();

    expect(PaymentPlan::withoutGlobalScopes()->where('patient_id', $patient->id)->exists())->toBeTrue();
});

it("rejects a plan opened for another clinic's patient", function (): void {
    ['owner' => $owner] = cppSetup();

    $clinicB = Clinic::factory()->create();
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patientB->id))
        ->assertSessionHasErrors('patient_id');

    expect(PaymentPlan::withoutGlobalScopes()->where('patient_id', $patientB->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// Treatment-Process entry point (PaymentSection "installment" mode)
// ---------------------------------------------------------------------------

it('creates a treatment-bound payment plan from the treatment Process installment mode', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment, 'clinic' => $clinic] = cppTreatmentSetup(200.00);

    $service = Service::withoutGlobalScopes()->where('clinic_id', $clinic->id)->first();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'none',
            'follow_up' => ['mode' => 'none'],
            'services' => [
                ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '200.00'],
            ],
            'installment_plan' => [
                'installment_count' => 2,
                'down_payment' => null,
                'installments' => [
                    ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '100.00'],
                    ['sequence' => 2, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => '100.00'],
                ],
            ],
        ])
        ->assertRedirect(route('treatments.show', $treatment));

    $plan = PaymentPlan::withoutGlobalScopes()->where('treatment_id', $treatment->id)->first();

    expect($plan)->not->toBeNull()
        ->and($plan->patient_id)->toBe($patient->id)
        ->and((float) $plan->total_amount)->toBe(200.0)
        ->and($plan->installment_count)->toBe(2)
        ->and($plan->installments()->count())->toBe(2);
});

it('rejects a treatment-Process installment plan whose sum does not match the computed total', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic] = cppTreatmentSetup(200.00);

    $service = Service::withoutGlobalScopes()->where('clinic_id', $clinic->id)->first();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'none',
            'follow_up' => ['mode' => 'none'],
            'services' => [
                ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '200.00'],
            ],
            'installment_plan' => [
                'installment_count' => 2,
                'down_payment' => null,
                'installments' => [
                    // Sums to 150, but the computed treatment total is 200.
                    ['sequence' => 1, 'due_date' => now()->addMonth()->toDateString(), 'amount' => '75.00'],
                    ['sequence' => 2, 'due_date' => now()->addMonths(2)->toDateString(), 'amount' => '75.00'],
                ],
            ],
        ])
        ->assertSessionHasErrors('installments');

    expect(PaymentPlan::withoutGlobalScopes()->where('treatment_id', $treatment->id)->exists())->toBeFalse();

    // The treatment must not have been left half-completed by the failed transaction.
    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)->toBe(TreatmentStatus::Draft);
});

// ---------------------------------------------------------------------------
// Inertia prop contract — patient Show sends the paymentPlans schedule
// ---------------------------------------------------------------------------

it('patient Show page sends paymentPlans with the installment schedule', function (): void {
    ['owner' => $owner, 'patient' => $patient] = cppSetup();

    $this->actingAs($owner)
        ->post(route('payment-plans.store'), cppPayload($patient->id));

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->has('paymentPlans', 1)
            ->where('paymentPlans.0.status', PaymentPlanStatus::Active->value)
            ->where('paymentPlans.0.total_amount', '300.00')
            ->where('paymentPlans.0.installment_count', 3)
            ->has('paymentPlans.0.installments', 3)
            ->where('paymentPlans.0.installments.0.status', InstallmentStatus::Pending->value)
        );
});
