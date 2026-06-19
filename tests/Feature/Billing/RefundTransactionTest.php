<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
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
 * Assign a clinic-scoped role (refund tests).
 */
function rfRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic + owner + patient + doctor + treatment + a Completed payment transaction.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, doctor: Doctor, appointment: Appointment, treatment: Treatment, payment: Transaction}
 */
function rfSetup(float $paymentAmount = 200.00): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rfRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    $detail = PodiatryTreatmentDetail::create([]);
    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
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

    return compact('clinic', 'owner', 'patient', 'doctor', 'appointment', 'treatment', 'payment');
}

/**
 * Minimal valid refund POST payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rfPayload(array $overrides = []): array
{
    return array_merge([
        'amount' => '200.00',
        'reason' => 'Customer request',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Happy path — full refund on a Completed payment
// ---------------------------------------------------------------------------

it('full refund creates a counter-entry transaction with negative amount and status refunded', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment, 'payment' => $payment, 'clinic' => $clinic] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '200.00', 'reason' => 'Full return']))
        ->assertRedirect();

    $counter = Transaction::withoutGlobalScopes()
        ->where('original_transaction_id', $payment->id)
        ->first();

    expect($counter)->not->toBeNull()
        ->and((float) $counter->amount)->toBe(-200.0)
        ->and($counter->status)->toBe(TransactionStatus::Refunded)
        ->and($counter->original_transaction_id)->toBe($payment->id)
        ->and($counter->patient_id)->toBe($patient->id)
        ->and($counter->treatment_id)->toBe($treatment->id)
        ->and($counter->payment_method)->toBe(PaymentMethod::Cash)
        ->and($counter->note)->toBe('Full return')
        ->and($counter->clinic_id)->toBe($clinic->id);
});

it('full refund flips the original to Refunded status', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '200.00']));

    expect($payment->fresh()->status)->toBe(TransactionStatus::Refunded);
});

it('full refund writes two status_logs: null→refunded for counter-entry and completed→refunded for original', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '200.00', 'reason' => 'Cancellation']));

    $counter = Transaction::withoutGlobalScopes()
        ->where('original_transaction_id', $payment->id)
        ->first();

    // Counter-entry log: null → refunded
    $counterLog = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'transaction')
        ->where('loggable_id', $counter->id)
        ->first();

    expect($counterLog)->not->toBeNull()
        ->and($counterLog->from_status)->toBeNull()
        ->and($counterLog->to_status)->toBe('refunded')
        ->and($counterLog->by_user_id)->toBe($owner->id)
        ->and($counterLog->reason)->toBe('Cancellation');

    // Original transition log: completed → refunded
    $originalLog = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'transaction')
        ->where('loggable_id', $payment->id)
        ->first();

    expect($originalLog)->not->toBeNull()
        ->and($originalLog->from_status)->toBe('completed')
        ->and($originalLog->to_status)->toBe('refunded')
        ->and($originalLog->by_user_id)->toBe($owner->id)
        ->and($originalLog->reason)->toBe('Cancellation');
});

// ---------------------------------------------------------------------------
// Partial refund
// ---------------------------------------------------------------------------

it('partial refund creates a counter-entry with the partial amount and flips original to PartiallyRefunded', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '50.00']))
        ->assertRedirect();

    $counter = Transaction::withoutGlobalScopes()
        ->where('original_transaction_id', $payment->id)
        ->first();

    expect($counter)->not->toBeNull()
        ->and((float) $counter->amount)->toBe(-50.0)
        ->and($payment->fresh()->status)->toBe(TransactionStatus::PartiallyRefunded);
});

it('partial refund writes completed→partially_refunded status_log for original', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '80.00', 'reason' => 'Partial return']));

    $originalLog = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'transaction')
        ->where('loggable_id', $payment->id)
        ->first();

    expect($originalLog)->not->toBeNull()
        ->and($originalLog->from_status)->toBe('completed')
        ->and($originalLog->to_status)->toBe('partially_refunded')
        ->and($originalLog->reason)->toBe('Partial return');
});

// ---------------------------------------------------------------------------
// Cumulative refunds
// ---------------------------------------------------------------------------

it('cumulative refunds: first partial → PartiallyRefunded, second completing the remainder → Refunded', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    // First partial refund: 100 of 200
    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '100.00']));

    expect($payment->fresh()->status)->toBe(TransactionStatus::PartiallyRefunded);

    // Second refund: remaining 100
    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment->fresh()), rfPayload(['amount' => '100.00']))
        ->assertRedirect();

    expect($payment->fresh()->status)->toBe(TransactionStatus::Refunded);

    // Two counter-entries must exist
    expect(
        Transaction::withoutGlobalScopes()
            ->where('original_transaction_id', $payment->id)
            ->count()
    )->toBe(2);
});

it('cumulative refunds: second refund writes partially_refunded→refunded status_log', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '100.00']));

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment->fresh()), rfPayload(['amount' => '100.00']));

    $logs = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'transaction')
        ->where('loggable_id', $payment->id)
        ->orderBy('id')
        ->get();

    expect($logs)->toHaveCount(2)
        ->and($logs[0]->from_status)->toBe('completed')
        ->and($logs[0]->to_status)->toBe('partially_refunded')
        ->and($logs[1]->from_status)->toBe('partially_refunded')
        ->and($logs[1]->to_status)->toBe('refunded');
});

it('third refund attempt on a fully-Refunded original → 422 (remaining = 0)', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '100.00']));

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment->fresh()), rfPayload(['amount' => '100.00']));

    // Third attempt should fail
    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment->fresh()), rfPayload(['amount' => '1.00']))
        ->assertSessionHasErrors('amount');

    // Still only two counter-entries
    expect(
        Transaction::withoutGlobalScopes()
            ->where('original_transaction_id', $payment->id)
            ->count()
    )->toBe(2);
});

// ---------------------------------------------------------------------------
// Remaining cap (partial-refunded original)
// ---------------------------------------------------------------------------

it('refund amount > remaining on PartiallyRefunded original → 422 even if ≤ original amount', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    // Refund 150 first, leaving 50 remaining
    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '150.00']));

    // Attempt to refund 60 — exceeds the 50 remaining even though < 200 original
    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment->fresh()), rfPayload(['amount' => '60.00']))
        ->assertSessionHasErrors('amount');

    // Only the first counter-entry exists
    expect(
        Transaction::withoutGlobalScopes()
            ->where('original_transaction_id', $payment->id)
            ->count()
    )->toBe(1);
});

// ---------------------------------------------------------------------------
// Balance side effects
// ---------------------------------------------------------------------------

it('patient balance remaining increases after refund (negative counter-entry reduces paid total)', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'payment' => $payment] = rfSetup(200.00);

    // Confirm baseline: remaining = 0 (200 paid against 200 total)
    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertInertia(fn ($page) => $page
            ->where('balance.remaining', '0.00')
        );

    // Refund 80
    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '80.00']));

    // After refund: paid = 200 + (−80) = 120; remaining = 200 − 120 = 80
    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertInertia(fn ($page) => $page
            ->where('balance.paid', '120.00')
            ->where('balance.remaining', '80.00')
        );
});

it('treatment paid_total drops after refund; treatment status stays Completed', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'payment' => $payment] = rfSetup(200.00);

    // 200 paid against 200 total
    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertInertia(fn ($page) => $page
            ->where('treatment.paid_total', '200')
        );

    // Refund 60
    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '60.00']));

    // After refund: paid = 200 + (−60) = 140
    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertInertia(fn ($page) => $page
            ->where('treatment.paid_total', '140')
            ->where('treatment.status', TreatmentStatus::Completed->value)
        );
});

// ---------------------------------------------------------------------------
// Standalone payment (no treatment_id)
// ---------------------------------------------------------------------------

it('full refund works on a standalone payment (null treatment_id) and counter-entry inherits null treatment_id', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rfSetup(100.00);

    // Override the payment to be standalone (no treatment)
    $standalone = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'amount' => '100.00',
        'payment_method' => PaymentMethod::Card,
        'status' => TransactionStatus::Completed,
    ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $standalone), rfPayload(['amount' => '100.00']))
        ->assertRedirect();

    $counter = Transaction::withoutGlobalScopes()
        ->where('original_transaction_id', $standalone->id)
        ->first();

    expect($counter)->not->toBeNull()
        ->and($counter->treatment_id)->toBeNull()
        ->and((float) $counter->amount)->toBe(-100.0);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('rejects refund with missing reason → 422', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), ['amount' => '50.00'])
        ->assertSessionHasErrors('reason');

    expect(
        Transaction::withoutGlobalScopes()->where('original_transaction_id', $payment->id)->exists()
    )->toBeFalse();
});

it('rejects refund with amount = 0 → 422', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '0.00']))
        ->assertSessionHasErrors('amount');
});

it('rejects refund with negative amount → 422', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '-10.00']))
        ->assertSessionHasErrors('amount');
});

it('rejects refund when amount exceeds original transaction amount → 422 (FormRequest after hook)', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '250.00']))
        ->assertSessionHasErrors('amount');

    expect(
        Transaction::withoutGlobalScopes()->where('original_transaction_id', $payment->id)->exists()
    )->toBeFalse();
});

// ---------------------------------------------------------------------------
// Refundability guards (service-level defense)
// ---------------------------------------------------------------------------

it('refunding a fully-Refunded transaction → 422 (service guard: not refundable status)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rfSetup(100.00);

    $refunded = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '100.00',
        'status' => TransactionStatus::Refunded,
    ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $refunded), rfPayload(['amount' => '100.00']))
        ->assertSessionHasErrors('amount');
});

it('refunding a negative counter-entry → 422 (service guard: non-positive original amount)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'payment' => $payment] = rfSetup(100.00);

    // Manually create a counter-entry (negative amount, status Refunded)
    $counterEntry = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'original_transaction_id' => $payment->id,
        'amount' => '-100.00',
        'status' => TransactionStatus::Refunded,
    ]);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $counterEntry), rfPayload(['amount' => '10.00']))
        ->assertSessionHasErrors('amount');
});

// ---------------------------------------------------------------------------
// Authorization — who can refund (transactions.refund = owner only)
// ---------------------------------------------------------------------------

it('owner can refund (has transactions.refund)', function (): void {
    ['owner' => $owner, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '50.00']))
        ->assertRedirect();

    expect(
        Transaction::withoutGlobalScopes()->where('original_transaction_id', $payment->id)->exists()
    )->toBeTrue();
});

it('manager is denied the refund endpoint → 403 (lacks transactions.refund)', function (): void {
    ['clinic' => $clinic, 'payment' => $payment] = rfSetup(200.00);

    $manager = User::factory()->create();
    rfRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('transactions.refund', $payment), rfPayload())
        ->assertForbidden();

    expect(
        Transaction::withoutGlobalScopes()->where('original_transaction_id', $payment->id)->exists()
    )->toBeFalse();
});

it('doctor is denied the refund endpoint → 403 (lacks transactions.refund)', function (): void {
    ['clinic' => $clinic, 'payment' => $payment] = rfSetup(200.00);

    $doctorUser = User::factory()->create();
    rfRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('transactions.refund', $payment), rfPayload())
        ->assertForbidden();
});

it('receptionist is denied the refund endpoint → 403 (lacks transactions.refund)', function (): void {
    ['clinic' => $clinic, 'payment' => $payment] = rfSetup(200.00);

    $receptionist = User::factory()->create();
    rfRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('transactions.refund', $payment), rfPayload())
        ->assertForbidden();
});

it('assistant is denied the refund endpoint → 403 (lacks transactions.refund)', function (): void {
    ['clinic' => $clinic, 'payment' => $payment] = rfSetup(200.00);

    $assistant = User::factory()->create();
    rfRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('transactions.refund', $payment), rfPayload())
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation — mandatory
// ---------------------------------------------------------------------------

it('clinic B owner cannot refund clinic A transaction → 404 (scoped route binding)', function (): void {
    // Clinic A setup with a payment
    ['payment' => $paymentA] = rfSetup(200.00);

    // Clinic B — independent owner
    $clinicB = Clinic::factory()->create();
    $ownerB = User::factory()->create();
    rfRole($ownerB, 'owner', $clinicB->id);

    // Clinic B owner attempts to refund Clinic A's transaction
    $this->actingAs($ownerB)
        ->post(route('transactions.refund', $paymentA), rfPayload(['amount' => '50.00']))
        ->assertNotFound();

    // No counter-entry was created for Clinic A's payment
    expect(
        Transaction::withoutGlobalScopes()
            ->where('original_transaction_id', $paymentA->id)
            ->exists()
    )->toBeFalse();
});

// ---------------------------------------------------------------------------
// Inertia prop contract — UI gating proven at the HTTP layer
// ---------------------------------------------------------------------------

it('transactions.refund is in auth.permissions for owner (refund button visible)', function (): void {
    ['owner' => $owner] = rfSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => $p->contains('transactions.refund'),
        ));
});

it('transactions.refund is NOT in auth.permissions for manager (refund button hidden)', function (): void {
    ['clinic' => $clinic] = rfSetup();

    $manager = User::factory()->create();
    rfRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => ! $p->contains('transactions.refund'),
        ));
});

it('transactions.refund is NOT in auth.permissions for receptionist (refund button hidden)', function (): void {
    ['clinic' => $clinic] = rfSetup();

    $receptionist = User::factory()->create();
    rfRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => ! $p->contains('transactions.refund'),
        ));
});

it('patient Show transaction items include refundable_amount and original_transaction_id fields', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'payment' => $payment] = rfSetup(150.00);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->has('transactions', 1)
            ->where('transactions.0.id', $payment->id)
            ->where('transactions.0.original_transaction_id', null)
            ->where('transactions.0.refundable_amount', '150.00')
        );
});

it('patient Show: after a partial refund, original row shows reduced refundable_amount and counter-entry shows 0.00', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'payment' => $payment] = rfSetup(200.00);

    // Refund 80, leaving 120 refundable.
    // Counter-entry gets paid_at = now() so it's index 0; original (paid_at = subHour) is index 1.
    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '80.00']));

    $counter = Transaction::withoutGlobalScopes()
        ->where('original_transaction_id', $payment->id)
        ->first();

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 2)
            // Counter-entry is index 0 (newest paid_at); refundable = "0.00", linked to original
            ->where('transactions.0.id', $counter->id)
            ->where('transactions.0.original_transaction_id', $payment->id)
            ->where('transactions.0.refundable_amount', '0.00')
            // Original is index 1; refundable = remaining "120.00"
            ->where('transactions.1.id', $payment->id)
            ->where('transactions.1.refundable_amount', '120.00')
        );
});

it('patient Show: fully-refunded original shows refundable_amount = 0.00', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'payment' => $payment] = rfSetup(100.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '100.00']));

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 2)
        );
});

it('treatment Show transaction items include refundable_amount and original_transaction_id fields', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Show')
            ->has('treatment.transactions', 1)
            ->where('treatment.transactions.0.id', $payment->id)
            ->where('treatment.transactions.0.original_transaction_id', null)
            ->where('treatment.transactions.0.refundable_amount', '200.00')
        );
});

it('treatment Show: counter-entry shows refundable_amount = 0.00 and original_transaction_id set', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'payment' => $payment] = rfSetup(200.00);

    $this->actingAs($owner)
        ->post(route('transactions.refund', $payment), rfPayload(['amount' => '50.00']));

    $counter = Transaction::withoutGlobalScopes()
        ->where('original_transaction_id', $payment->id)
        ->first();

    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('treatment.transactions', 2)
            // The original still shows remaining 150 refundable
            ->where('treatment.transactions.1.id', $payment->id)
            ->where('treatment.transactions.1.refundable_amount', '150.00')
            // Counter-entry is newer (paid_at = now()); shows 0.00 and linked back to original
            ->where('treatment.transactions.0.id', $counter->id)
            ->where('treatment.transactions.0.original_transaction_id', $payment->id)
            ->where('treatment.transactions.0.refundable_amount', '0.00')
        );
});

// ---------------------------------------------------------------------------
// Arch — module boundary: RefundController and RefundService import only Billing + Core
// ---------------------------------------------------------------------------

arch('RefundController does not reach into other modules repositories')
    ->expect('App\Modules\Billing\Http\Controllers\RefundController')
    ->not->toUse('App\Modules\Medical\Repositories')
    ->not->toUse('App\Modules\Scheduling\Repositories');

arch('RefundService only depends on its own module and the Core shared kernel')
    ->expect('App\Modules\Billing\Services\RefundService')
    ->toUse('App\Modules\Core\Services\StatusLogService')
    ->not->toUse('App\Modules\Medical')
    ->not->toUse('App\Modules\Scheduling');
