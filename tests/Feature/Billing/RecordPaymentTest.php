<?php

use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
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
 * Assign a clinic-scoped role (record-payment tests).
 */
function rpRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic + owner + patient + a treatment at the given status and total_amount.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, doctor: Doctor, appointment: Appointment, treatment: Treatment}
 */
function rpSetup(float $totalAmount = 200.00, TreatmentStatus $status = TreatmentStatus::Completed): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rpRole($owner, 'owner', $clinic->id);

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
        'subtotal_amount' => $totalAmount,
        'discount_amount' => 0,
        'total_amount' => $totalAmount,
        'status' => $status,
        'completed_at' => $status === TreatmentStatus::Completed ? now() : null,
        'created_by' => $owner->id,
    ]);

    return compact('clinic', 'owner', 'patient', 'doctor', 'appointment', 'treatment');
}

/**
 * Minimal valid POST /payments payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rpPayload(int $patientId, array $overrides = []): array
{
    return array_merge([
        'patient_id' => $patientId,
        'treatment_id' => null,
        'amount' => '50.00',
        'payment_method' => 'cash',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Happy path — treatment-bound payment
// ---------------------------------------------------------------------------

it('records a treatment-bound payment and creates a Completed transaction', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment, 'clinic' => $clinic] = rpSetup(200.00);

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, [
            'treatment_id' => $treatment->id,
            'amount' => '150.00',
            'payment_method' => 'card',
        ]))
        ->assertRedirect();

    $tx = Transaction::withoutGlobalScopes()
        ->where('treatment_id', $treatment->id)
        ->first();

    expect($tx)->not->toBeNull()
        ->and($tx->status)->toBe(TransactionStatus::Completed)
        ->and((float) $tx->amount)->toBe(150.0)
        ->and($tx->payment_method->value)->toBe('card')
        ->and($tx->patient_id)->toBe($patient->id)
        ->and($tx->clinic_id)->toBe($clinic->id)
        ->and($tx->created_by)->toBe($owner->id);
});

it('writes a null → completed status_log for the transaction', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = rpSetup(200.00);

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, [
            'treatment_id' => $treatment->id,
            'amount' => '100.00',
            'payment_method' => 'cash',
        ]));

    $tx = Transaction::withoutGlobalScopes()
        ->where('treatment_id', $treatment->id)
        ->first();

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'transaction')
        ->where('loggable_id', $tx->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBeNull()
        ->and($log->to_status)->toBe('completed')
        ->and($log->by_user_id)->toBe($owner->id);
});

it('persists optional note and paid_at when provided', function (): void {
    ['owner' => $owner, 'patient' => $patient] = rpSetup();

    $paidAt = now()->subDays(3)->format('Y-m-d');

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, [
            'note' => 'Ön ödeme',
            'paid_at' => $paidAt,
        ]));

    $tx = Transaction::withoutGlobalScopes()
        ->where('patient_id', $patient->id)
        ->first();

    expect($tx->note)->toBe('Ön ödeme')
        ->and($tx->paid_at->format('Y-m-d'))->toBe($paidAt);
});

// ---------------------------------------------------------------------------
// Standalone payment (no treatment)
// ---------------------------------------------------------------------------

it('records a standalone payment with null treatment_id', function (): void {
    ['owner' => $owner, 'patient' => $patient] = rpSetup();

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, [
            'treatment_id' => null,
            'amount' => '75.00',
            'payment_method' => 'transfer',
        ]))
        ->assertRedirect();

    $tx = Transaction::withoutGlobalScopes()
        ->where('patient_id', $patient->id)
        ->first();

    expect($tx)->not->toBeNull()
        ->and($tx->treatment_id)->toBeNull()
        ->and((float) $tx->amount)->toBe(75.0)
        ->and($tx->payment_method->value)->toBe('transfer');
});

// ---------------------------------------------------------------------------
// Prepayment — Draft treatment is NOT capped
// ---------------------------------------------------------------------------

it('allows a prepayment against a Draft treatment exceeding its current total (uncapped)', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = rpSetup(100.00, TreatmentStatus::Draft);

    // Amount (300) > treatment total (100) — must NOT be rejected for Draft
    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, [
            'treatment_id' => $treatment->id,
            'amount' => '300.00',
            'payment_method' => 'cash',
        ]))
        ->assertRedirect();

    expect(
        Transaction::withoutGlobalScopes()
            ->where('treatment_id', $treatment->id)
            ->exists()
    )->toBeTrue();
});

// ---------------------------------------------------------------------------
// Overpayment guard — Completed treatment
// ---------------------------------------------------------------------------

it('rejects overpayment on a Completed treatment with the payments_exceed_total message', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment, 'clinic' => $clinic] = rpSetup(100.00);

    // Seed an existing 80.00 payment
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '80.00',
        'status' => TransactionStatus::Completed,
    ]);

    // New 30.00 would push total to 110 > 100
    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, [
            'treatment_id' => $treatment->id,
            'amount' => '30.00',
            'payment_method' => 'cash',
        ]))
        ->assertSessionHasErrors('amount');

    // Only the pre-seeded transaction exists; no second row was created
    expect(
        Transaction::withoutGlobalScopes()
            ->where('treatment_id', $treatment->id)
            ->count()
    )->toBe(1);
});

it('allows a payment on a Completed treatment that exactly fills the remaining balance', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment, 'clinic' => $clinic] = rpSetup(100.00);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '60.00',
        'status' => TransactionStatus::Completed,
    ]);

    // Exact remainder: 40.00
    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, [
            'treatment_id' => $treatment->id,
            'amount' => '40.00',
            'payment_method' => 'cash',
        ]))
        ->assertRedirect();

    expect(
        Transaction::withoutGlobalScopes()
            ->where('treatment_id', $treatment->id)
            ->count()
    )->toBe(2);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('rejects an invalid payment_method', function (): void {
    ['owner' => $owner, 'patient' => $patient] = rpSetup();

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, ['payment_method' => 'bitcoin']))
        ->assertSessionHasErrors('payment_method');
});

it('rejects a zero amount', function (): void {
    ['owner' => $owner, 'patient' => $patient] = rpSetup();

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, ['amount' => '0.00']))
        ->assertSessionHasErrors('amount');
});

it('rejects a negative amount', function (): void {
    ['owner' => $owner, 'patient' => $patient] = rpSetup();

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, ['amount' => '-10.00']))
        ->assertSessionHasErrors('amount');
});

it('requires patient_id', function (): void {
    ['owner' => $owner] = rpSetup();

    $this->actingAs($owner)
        ->post(route('payments.store'), ['amount' => '50.00', 'payment_method' => 'cash'])
        ->assertSessionHasErrors('patient_id');
});

it('rejects paid_at set in the future', function (): void {
    ['owner' => $owner, 'patient' => $patient] = rpSetup();

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id, [
            'paid_at' => now()->addDay()->format('Y-m-d'),
        ]))
        ->assertSessionHasErrors('paid_at');
});

// ---------------------------------------------------------------------------
// Authorization — who can record a payment
// ---------------------------------------------------------------------------

it('allows an owner to record a payment (has transactions.create)', function (): void {
    ['owner' => $owner, 'patient' => $patient] = rpSetup();

    $this->actingAs($owner)
        ->post(route('payments.store'), rpPayload($patient->id))
        ->assertRedirect();
});

it('allows a manager to record a payment (has transactions.create)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = rpSetup();
    $manager = User::factory()->create();
    rpRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('payments.store'), rpPayload($patient->id))
        ->assertRedirect();
});

it('allows a doctor to record a payment (has transactions.create)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = rpSetup();
    $doctorUser = User::factory()->create();
    rpRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('payments.store'), rpPayload($patient->id))
        ->assertRedirect();
});

it('allows a receptionist to record a payment (has transactions.create)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = rpSetup();
    $receptionist = User::factory()->create();
    rpRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('payments.store'), rpPayload($patient->id))
        ->assertRedirect();
});

it('returns 403 for an assistant who lacks transactions.create', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = rpSetup();
    $assistant = User::factory()->create();
    rpRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('payments.store'), rpPayload($patient->id))
        ->assertForbidden();
});

it('returns 302 to login for an unauthenticated request', function (): void {
    ['patient' => $patient] = rpSetup();

    $this->post(route('payments.store'), rpPayload($patient->id))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation — mandatory
// ---------------------------------------------------------------------------

it('rejects clinic B patient_id in a clinic A request (exists rule scoped to active clinic)', function (): void {
    ['owner' => $ownerA] = rpSetup();
    ['patient' => $patientB] = rpSetup(); // second independent clinic

    // Owner A posts with Clinic B's patient — must fail validation
    $this->actingAs($ownerA)
        ->post(route('payments.store'), rpPayload($patientB->id))
        ->assertSessionHasErrors('patient_id');

    // No transaction ever created for clinic B's patient
    expect(
        Transaction::withoutGlobalScopes()
            ->where('patient_id', $patientB->id)
            ->exists()
    )->toBeFalse();
});

it('rejects clinic B treatment_id in a clinic A request (exists rule scoped to active clinic)', function (): void {
    ['owner' => $ownerA, 'patient' => $patientA] = rpSetup();
    ['treatment' => $treatmentB] = rpSetup(); // second independent clinic

    // Owner A posts with A's patient but B's treatment
    $this->actingAs($ownerA)
        ->post(route('payments.store'), rpPayload($patientA->id, [
            'treatment_id' => $treatmentB->id,
        ]))
        ->assertSessionHasErrors('treatment_id');

    // No transaction against Clinic B's treatment
    expect(
        Transaction::withoutGlobalScopes()
            ->where('treatment_id', $treatmentB->id)
            ->exists()
    )->toBeFalse();
});

// ---------------------------------------------------------------------------
// Inertia prop contract — UI gating proven at the HTTP layer
// ---------------------------------------------------------------------------

it('includes transactions.create in auth.permissions for owner (Tahsilat al button visible)', function (): void {
    ['owner' => $owner] = rpSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => $p->contains('transactions.create'),
        ));
});

it('does not include transactions.create in auth.permissions for assistant (button hidden)', function (): void {
    ['clinic' => $clinic] = rpSetup();
    $assistant = User::factory()->create();
    rpRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => ! $p->contains('transactions.create'),
        ));
});

it('treatment Show page sends total_amount and paid_total for the record-payment dialog', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic, 'patient' => $patient] = rpSetup(150.00);

    // 50.00 already paid
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '50.00',
        'status' => TransactionStatus::Completed,
    ]);

    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Show')
            ->where('treatment.total_amount', '150.00')
            ->where('treatment.paid_total', '50')
        );
});

it('patient Show page sends treatments array with status for the standalone payment dialog', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = rpSetup(200.00, TreatmentStatus::Completed);

    // Add a Draft treatment for the same patient
    $appointment2 = Appointment::factory()->create([
        'clinic_id' => $treatment->clinic_id,
        'patient_id' => $patient->id,
        'doctor_id' => $treatment->doctor_id,
    ]);
    Treatment::create([
        'clinic_id' => $treatment->clinic_id,
        'appointment_id' => $appointment2->id,
        'patient_id' => $patient->id,
        'doctor_id' => $treatment->doctor_id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Draft,
        'created_by' => null,
    ]);

    // listForPatient orders by created_at DESC — Draft (created last) comes first
    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->has('treatments', 2)
            ->where('treatments.0.status', TreatmentStatus::Draft->value)
            ->where('treatments.1.status', TreatmentStatus::Completed->value)
        );
});
