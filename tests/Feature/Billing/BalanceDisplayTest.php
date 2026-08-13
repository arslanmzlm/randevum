<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
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

/**
 * Assign a clinic-scoped role (balance-display tests).
 */
function bdRole(User $user, string $role, int $clinicId): void
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
function bdSetup(float $totalAmount = 300.00, TreatmentStatus $status = TreatmentStatus::Completed): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    bdRole($owner, 'owner', $clinic->id);

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

// ---------------------------------------------------------------------------
// Treatment Show — transaction list in prop
// ---------------------------------------------------------------------------

it('treatment Show sends treatment.transactions with correct shape when user has transactions.viewAny', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(200.00);

    $tx = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '120.00',
        'payment_method' => PaymentMethod::Card,
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subDay(),
        'note' => 'Kart ile ödeme',
    ]);

    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Show')
            ->has('treatment.transactions', 1)
            ->where('treatment.transactions.0.id', $tx->id)
            ->where('treatment.transactions.0.payment_method', 'card')
            ->where('treatment.transactions.0.amount', '120.00')
            ->where('treatment.transactions.0.note', 'Kart ile ödeme')
            ->where('treatment.transactions.0.status', 'completed')
            ->where('treatment.transactions.0.treatment_id', $treatment->id)
        );
});

it('treatment Show orders transactions newest first (paid_at DESC, id DESC)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(300.00);

    $older = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '100.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subDays(2),
    ]);

    $newer = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '150.00',
        'payment_method' => PaymentMethod::Transfer,
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subDay(),
    ]);

    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('treatment.transactions', 2)
            ->where('treatment.transactions.0.id', $newer->id)
            ->where('treatment.transactions.1.id', $older->id)
        );
});

it('treatment Show sends empty transactions array and zero paid_total when the treatment has no payments', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = bdSetup(200.00);

    $this->actingAs($owner)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('treatment.transactions', [])
            ->where('treatment.paid_total', '0')
        );
});

// ---------------------------------------------------------------------------
// Treatment Show — permission gate (transactions.viewAny)
// ---------------------------------------------------------------------------

it('treatment Show omits treatment.transactions prop for assistant who lacks transactions.viewAny', function (): void {
    ['clinic' => $clinic, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(200.00);

    $assistant = User::factory()->create();
    bdRole($assistant, 'assistant', $clinic->id);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '100.00',
        'status' => TransactionStatus::Completed,
    ]);

    $this->actingAs($assistant)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Show')
            ->missing('treatment.transactions')
        );
});

it('treatment Show includes treatment.transactions prop for receptionist who has transactions.viewAny', function (): void {
    ['clinic' => $clinic, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(200.00);

    $receptionist = User::factory()->create();
    bdRole($receptionist, 'receptionist', $clinic->id);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '80.00',
        'status' => TransactionStatus::Completed,
    ]);

    $this->actingAs($receptionist)
        ->get(route('treatments.show', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('treatment.transactions', 1)
        );
});

// ---------------------------------------------------------------------------
// Patient Show — balance + transactions prop
// ---------------------------------------------------------------------------

it('patient Show sends balance (total/paid/remaining) and transactions including a standalone payment', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(300.00);

    // Treatment-bound payment (older)
    $txTreatment = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '150.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subDay(),
    ]);

    // Standalone payment (newer — null treatment_id)
    $txStandalone = Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'amount' => '75.00',
        'payment_method' => PaymentMethod::Card,
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->where('balance.total', '300.00')
            ->has('transactions', 2)
            // standalone is newest → first in list
            ->where('transactions.0.id', $txStandalone->id)
            ->where('transactions.0.treatment_id', null)
            ->where('transactions.1.id', $txTreatment->id)
            ->where('transactions.1.treatment_id', $treatment->id)
        );
});

it('patient Show balance remaining equals total minus paid', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(300.00);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '200.00',
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('balance.total', '300.00')
            ->where('balance.paid', '200.00')
            ->where('balance.remaining', '100.00')
        );
});

it('patient Show balance correctly nets a refund — remaining goes up when a counter-entry reduces paid', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(200.00);

    // Original payment
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '150.00',
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subDays(2),
    ]);

    // Refund counter-entry — negative amount (domain rule: new counter-entry, never hard-deleted)
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '-50.00',
        'status' => TransactionStatus::Refunded,
        'paid_at' => now()->subDay(),
    ]);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('balance.total', '200.00')
            ->where('balance.paid', '100.00')       // 150 + (−50) = 100
            ->where('balance.remaining', '100.00')
            ->has('transactions', 2)
        );
});

it('patient Show sends empty transactions array and zero paid when the patient has no payments', function (): void {
    // Completed treatment (counts in total) but zero transactions
    ['owner' => $owner, 'patient' => $patient] = bdSetup(200.00);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('transactions', [])
            ->where('balance.total', '200.00')
            ->where('balance.paid', '0.00')
            ->where('balance.remaining', '200.00')
        );
});

it('patient Show total is zero when no Completed treatments exist', function (): void {
    // Draft treatment — not counted in balance total (only Completed treatments count)
    ['owner' => $owner, 'patient' => $patient] = bdSetup(150.00, TreatmentStatus::Draft);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('transactions', [])
            ->where('balance.total', '0.00')
            ->where('balance.paid', '0.00')
            ->where('balance.remaining', '0.00')
        );
});

// ---------------------------------------------------------------------------
// Patient Show — permission gate (transactions.viewAny)
// ---------------------------------------------------------------------------

it('patient Show includes balance and transactions props for receptionist (has transactions.viewAny)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(200.00);

    $receptionist = User::factory()->create();
    bdRole($receptionist, 'receptionist', $clinic->id);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '80.00',
        'status' => TransactionStatus::Completed,
    ]);

    $this->actingAs($receptionist)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->has('balance')
            ->has('transactions')
        );
});

it('patient Show omits balance and transactions props for assistant (lacks transactions.viewAny)', function (): void {
    ['clinic' => $clinic, 'patient' => $patient, 'treatment' => $treatment] = bdSetup(200.00);

    $assistant = User::factory()->create();
    bdRole($assistant, 'assistant', $clinic->id);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '80.00',
        'status' => TransactionStatus::Completed,
    ]);

    $this->actingAs($assistant)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->missing('balance')
            ->missing('transactions')
        );
});

// ---------------------------------------------------------------------------
// Inertia prop contract — auth.permissions UI gate
// ---------------------------------------------------------------------------

it('includes transactions.viewAny in auth.permissions for owner (money section visible)', function (): void {
    ['owner' => $owner] = bdSetup();

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => $p->contains('transactions.viewAny'),
        ));
});

it('does not include transactions.viewAny in auth.permissions for assistant (money section hidden)', function (): void {
    ['clinic' => $clinic] = bdSetup();
    $assistant = User::factory()->create();
    bdRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => ! $p->contains('transactions.viewAny'),
        ));
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation — HTTP layer
// ---------------------------------------------------------------------------

it('patient Show only returns clinic A transactions even if a clinic-B transaction shares the patient_id', function (): void {
    ['clinic' => $clinicA, 'owner' => $ownerA, 'patient' => $patientA, 'treatment' => $treatmentA] = bdSetup(200.00);

    $txA = Transaction::factory()->create([
        'clinic_id' => $clinicA->id,
        'patient_id' => $patientA->id,
        'treatment_id' => $treatmentA->id,
        'amount' => '100.00',
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subDay(),
    ]);

    // Inject clinic-B transaction that shares patient_id — simulates a cross-clinic leak
    $clinicB = Clinic::factory()->create();
    Transaction::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientA->id,
        'treatment_id' => null,
        'amount' => '999.00',
        'payment_method' => PaymentMethod::Cash->value,
        'status' => TransactionStatus::Completed->value,
        'paid_at' => now(),
        'note' => null,
        'created_by' => null,
    ]);

    $this->actingAs($ownerA)
        ->get(route('patients.show', $patientA))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('transactions', 1)
            ->where('transactions.0.id', $txA->id)
            ->where('balance.paid', '100.00')       // 999 from clinic B must NOT add in
        );
});

it('treatment Show only returns clinic A transactions even if a clinic-B transaction shares the treatment_id', function (): void {
    ['clinic' => $clinicA, 'owner' => $ownerA, 'patient' => $patientA, 'treatment' => $treatmentA] = bdSetup(200.00);

    $txA = Transaction::factory()->create([
        'clinic_id' => $clinicA->id,
        'patient_id' => $patientA->id,
        'treatment_id' => $treatmentA->id,
        'amount' => '100.00',
        'status' => TransactionStatus::Completed,
        'paid_at' => now()->subDay(),
    ]);

    // Inject clinic-B transaction that shares treatment_id
    $clinicB = Clinic::factory()->create();
    Transaction::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientA->id,
        'treatment_id' => $treatmentA->id,
        'amount' => '999.00',
        'payment_method' => PaymentMethod::Cash->value,
        'status' => TransactionStatus::Completed->value,
        'paid_at' => now(),
        'note' => null,
        'created_by' => null,
    ]);

    $this->actingAs($ownerA)
        ->get(route('treatments.show', $treatmentA))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('treatment.transactions', 1)
            ->where('treatment.transactions.0.id', $txA->id)
            ->where('treatment.paid_total', '100')  // 999 from clinic B must NOT add in
        );
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation — repository layer
// ---------------------------------------------------------------------------

it('TransactionRepository paidTotalForPatient is scoped to the active clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    // Clinic A: 200.00 for this patient
    Transaction::factory()->create([
        'clinic_id' => $clinicA->id,
        'patient_id' => $patient->id,
        'amount' => '200.00',
        'status' => TransactionStatus::Completed,
    ]);

    // Clinic B: 500.00 also referencing the same patient_id (out-of-scope)
    Transaction::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'amount' => '500.00',
        'payment_method' => PaymentMethod::Cash->value,
        'status' => TransactionStatus::Completed->value,
        'paid_at' => now(),
        'note' => null,
        'created_by' => null,
    ]);

    app(ClinicContext::class)->set($clinicA->id);

    $repo = app(TransactionRepository::class);

    expect($repo->paidTotalForPatient($patient->id))->toBe('200.00'); // not 700
});

it('TransactionRepository forPatient is scoped to the active clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $txA = Transaction::factory()->create([
        'clinic_id' => $clinicA->id,
        'patient_id' => $patient->id,
        'amount' => '100.00',
        'status' => TransactionStatus::Completed,
    ]);

    Transaction::withoutGlobalScopes()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'amount' => '999.00',
        'payment_method' => PaymentMethod::Cash->value,
        'status' => TransactionStatus::Completed->value,
        'paid_at' => now(),
        'note' => null,
        'created_by' => null,
    ]);

    app(ClinicContext::class)->set($clinicA->id);

    $repo = app(TransactionRepository::class);
    $results = $repo->forPatient($patient->id);

    expect($results)->toHaveCount(1)
        ->and($results->first()->id)->toBe($txA->id);
});

// ---------------------------------------------------------------------------
// Arch — PatientController must import through the Contract, not the concrete service/repo
// ---------------------------------------------------------------------------

arch('PatientController uses BalanceReaderContract not the concrete BalanceService or TransactionRepository')
    ->expect('App\Modules\Medical\Http\Controllers\PatientController')
    ->toUse('App\Modules\Billing\Contracts\BalanceReaderContract')
    ->not->toUse('App\Modules\Billing\Services\BalanceService')
    ->not->toUse('App\Modules\Billing\Repositories\TransactionRepository');
