<?php

use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Modules\Billing\Services\PaymentService;
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
 * Assign a clinic-scoped role (payment overpayment concurrency tests).
 */
function pocRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic + owner + patient + a Completed treatment at the given total.
 *
 * @return array{clinic: Clinic, owner: User, patient: Patient, doctor: Doctor, treatment: Treatment}
 */
function pocSetup(float $totalAmount = 100.00): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pocRole($owner, 'owner', $clinic->id);

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
        'subtotal_amount' => $totalAmount,
        'discount_amount' => 0,
        'total_amount' => $totalAmount,
        'status' => TreatmentStatus::Completed,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    return compact('clinic', 'owner', 'patient', 'doctor', 'treatment');
}

// ---------------------------------------------------------------------------
// The overpayment cap check locks the treatment row and re-reads the paid total inside
// the open DB transaction (regression for the B1 code-hunt finding — see PaymentService::record)
// ---------------------------------------------------------------------------

it('locks the treatment row and re-reads the paid total inside the open DB transaction', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = pocSetup(100.00);
    app(ClinicContext::class)->set($clinic->id);

    // RefreshDatabase already holds the suite at transaction level 1 — compare against this
    // captured base, not against 0.
    $base = DB::transactionLevel();
    $levels = [];

    DB::listen(function ($query) use (&$levels): void {
        $sql = strtolower($query->sql);

        // The lockForUpdate() re-read of the treatment by its own id (TreatmentRepository::lockForUpdate).
        if (str_contains($sql, 'from "treatments"') && str_contains($sql, '"id" = ?')) {
            $levels['locked_treatment'] = DB::transactionLevel();
        }

        // The paidTotalForTreatment() SUM query against transactions, scoped by treatment_id.
        if (str_contains($sql, 'sum(') && str_contains($sql, 'transactions') && str_contains($sql, 'treatment_id')) {
            $levels['paid_total_sum'] = DB::transactionLevel();
        }
    });

    app(PaymentService::class)->record([
        'amount' => '30.00',
        'payment_method' => 'cash',
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
    ], $owner);

    expect($levels['locked_treatment'] ?? null)->not->toBeNull()
        ->and($levels['locked_treatment'])->toBeGreaterThan($base)
        ->and($levels['paid_total_sum'] ?? null)->not->toBeNull()
        ->and($levels['paid_total_sum'])->toBeGreaterThan($base);
});

// ---------------------------------------------------------------------------
// A second payment against the same treatment is rejected once the first has filled the cap
// ---------------------------------------------------------------------------

it('a second payment against a shrunk remaining is rejected once the first has filled the cap', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = pocSetup(100.00);
    app(ClinicContext::class)->set($clinic->id);

    app(PaymentService::class)->record([
        'amount' => '80.00',
        'payment_method' => 'cash',
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
    ], $owner);

    // 80 already paid; 30 more would push the total to 110 > 100 — must be rejected even
    // though a lockless read of "existingPaid" taken before the first payment committed
    // would have seen 0 and let it through.
    expect(fn () => app(PaymentService::class)->record([
        'amount' => '30.00',
        'payment_method' => 'cash',
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
    ], $owner))->toThrow(ValidationException::class);

    expect(Transaction::withoutGlobalScopes()->where('treatment_id', $treatment->id)->count())->toBe(1)
        ->and((float) Transaction::withoutGlobalScopes()->where('treatment_id', $treatment->id)->sum('amount'))->toBe(80.0);
});

// ---------------------------------------------------------------------------
// The same race, exercised over HTTP — two requests submitted with amounts that were each
// individually valid before either had committed. The first collects; the second's amount
// now pushes the total over the cap → the overpayment guard rejects it.
// ---------------------------------------------------------------------------

it('a double-submitted HTTP payment rejects the second POST once the cap has been filled under it', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = pocSetup(100.00);

    $this->actingAs($owner)
        ->post(route('payments.store'), [
            'patient_id' => $patient->id,
            'treatment_id' => $treatment->id,
            'amount' => '80.00',
            'payment_method' => 'cash',
        ])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post(route('payments.store'), [
            'patient_id' => $patient->id,
            'treatment_id' => $treatment->id,
            'amount' => '80.00',
            'payment_method' => 'cash',
        ])
        ->assertSessionHasErrors('amount');

    expect(Transaction::withoutGlobalScopes()->where('treatment_id', $treatment->id)->count())->toBe(1);
});
