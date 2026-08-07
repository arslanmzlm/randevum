<?php

use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Billing\Repositories\TransactionRepository;
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

function mibRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A clinic + owner + patient + a Completed treatment (total 200.00) with a 150.00 patient
 * payment already on it, plus a 500.00 manual income row that must never leak into any
 * patient- or treatment-scoped figure.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, treatment: Treatment, payment: Transaction, manual: Transaction}
 */
function mibSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    mibRole($owner, 'owner', $clinic->id);

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
        'subtotal_amount' => 200.00,
        'discount_amount' => 0,
        'total_amount' => 200.00,
        'status' => TreatmentStatus::Completed,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    $payment = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '150.00',
        'status' => TransactionStatus::Completed,
    ]);

    $manual = Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'amount' => '500.00',
    ]);

    return compact('clinic', 'owner', 'patient', 'treatment', 'payment', 'manual');
}

it("manual income never counts in a patient's derived balance/paid total", function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = mibSetup();

    app(ClinicContext::class)->set($clinic->id);
    $repo = app(TransactionRepository::class);

    expect($repo->paidTotalForPatient($patient->id))->toBe('150.00');
});

it('BalanceService::transactionsForPatient never includes the manual income row', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'payment' => $payment] = mibSetup();

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $payment->id)
            ->where('balance.paid', '150.00')
            ->where('balance.remaining', '50.00')
        );
});

it('treatment paid total and overpayment cap ignore the manual income row', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = mibSetup();

    // paid_total is an unformatted numeric string (no trailing .00) — matches the existing
    // RecordPaymentTest/RefundTransactionTest convention.
    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('treatment.paid_total', '150'));

    // Only 50.00 of headroom remains (200 total − 150 paid); the 500.00 manual row must not
    // count toward the cap, so a 60.00 payment must be rejected as overpayment.
    $this->actingAs($owner)
        ->post(route('payments.store'), [
            'patient_id' => $treatment->patient_id,
            'treatment_id' => $treatment->id,
            'amount' => '60.00',
            'payment_method' => 'cash',
        ])
        ->assertSessionHasErrors('amount');
});

it('paidTotalsForPatients ignores the manual income row', function (): void {
    ['clinic' => $clinic, 'patient' => $patient] = mibSetup();

    app(ClinicContext::class)->set($clinic->id);
    $repo = app(TransactionRepository::class);

    expect($repo->paidTotalsForPatients([$patient->id]))->toBe([$patient->id => '150']);
});

it('the patients-list balance column (remaining) is unaffected by manual income', function (): void {
    ['owner' => $owner, 'patient' => $patient] = mibSetup();

    // billed 200.00 − paid 150.00 = remaining 50.00; the 500.00 manual row must not enter
    // either side of this derivation.
    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where("balances.{$patient->id}", '50.00'));
});
