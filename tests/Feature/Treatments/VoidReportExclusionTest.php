<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Product;
use App\Models\Service;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\TreatmentProductLine;
use App\Models\TreatmentServiceLine;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
    Cache::flush();
});

function vreRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A clinic with one completed treatment (300.00 service + 200.00 product = 500.00) on a
 * past appointment, plus its owner. Nothing is paid — the caller adds transactions.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, appointment: Appointment, treatment: Treatment, service: Service, product: Product}
 */
function vreSetup(?AppointmentType $type = null): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    vreRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'appointment_type_id' => $type?->id,
        'starts_at' => '2026-06-10 09:00:00',
    ]);

    $treatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'subtotal_amount' => '500.00',
        'discount_amount' => '0.00',
        'total_amount' => '500.00',
        'completed_at' => '2026-06-10 10:00:00',
    ]);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);
    TreatmentServiceLine::create([
        'clinic_id' => $clinic->id,
        'treatment_id' => $treatment->id,
        'service_id' => $service->id,
        'quantity' => 1,
        'unit_price' => '300.00',
        'discount_amount' => '0.00',
        'subtotal' => '300.00',
        'sort_order' => 0,
    ]);

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'current_stock' => 10]);
    TreatmentProductLine::create([
        'clinic_id' => $clinic->id,
        'treatment_id' => $treatment->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => '100.00',
        'discount_amount' => '0.00',
        'subtotal' => '200.00',
        'sort_order' => 0,
    ]);

    return compact('clinic', 'owner', 'doctor', 'patient', 'appointment', 'treatment', 'service', 'product');
}

/**
 * A settled payment and its refund counter-entry, dated independently so a report window
 * can contain the payment without the refund — the case where netting alone would still
 * show the money. paidTotal nets to zero over all time, which is what void requires.
 */
function vrePayThenRefund(Treatment $treatment, string $amount, string $paidAt, string $refundedAt): void
{
    $payment = Transaction::factory()->create([
        'clinic_id' => $treatment->clinic_id,
        'patient_id' => $treatment->patient_id,
        'treatment_id' => $treatment->id,
        'amount' => $amount,
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Refunded,
        'paid_at' => $paidAt,
    ]);

    Transaction::factory()->create([
        'clinic_id' => $treatment->clinic_id,
        'patient_id' => $treatment->patient_id,
        'treatment_id' => $treatment->id,
        'original_transaction_id' => $payment->id,
        'amount' => '-'.$amount,
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => $refundedAt,
    ]);
}

function vreVoid(User $owner, Treatment $treatment): void
{
    test()->actingAs($owner)
        ->post(route('treatments.void', $treatment))
        ->assertRedirect(route('treatments.show', $treatment));

    expect(Treatment::withoutGlobalScopes()->find($treatment->id)->status)
        ->toBe(TreatmentStatus::Voided);

    // No manual Cache::flush() here on purpose: the void itself drops the clinic's
    // revenue cache, so the report reads below must already be fresh.
}

// ---------------------------------------------------------------------------
// Report breakdown tabs
// ---------------------------------------------------------------------------

it('drops a voided treatment out of the service and product tabs', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'service' => $service, 'product' => $product] = vreSetup();

    $range = ['start' => '2026-06-01', 'end' => '2026-06-30'];

    $this->actingAs($owner)
        ->get(route('reports.index', [...$range, 'tab' => 'service']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $service->id)
            ->where('breakdown.data.0.amount', '300.00')
            ->where('breakdown.totals.amount', '300.00')
        );

    $this->actingAs($owner)
        ->get(route('reports.index', [...$range, 'tab' => 'product']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $product->id)
            ->where('breakdown.data.0.amount', '200.00')
        );

    vreVoid($owner, $treatment);

    $this->actingAs($owner)
        ->get(route('reports.index', [...$range, 'tab' => 'service']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 0)
            ->where('breakdown.totals.amount', '0.00')
            ->where('breakdown.totals.count', 0)
        );

    $this->actingAs($owner)
        ->get(route('reports.index', [...$range, 'tab' => 'product']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 0)
            ->where('breakdown.totals.amount', '0.00')
        );
});

it('drops a voided treatment money out of the doctor tab, including a window that holds only the payment', function (): void {
    ['owner' => $owner, 'doctor' => $doctor, 'treatment' => $treatment] = vreSetup();

    // Paid on the 10th, refunded on the 12th: the window below sees the payment only, so
    // the payment+refund netting cannot hide the money — the void exclusion has to.
    vrePayThenRefund($treatment, '400.00', '2026-06-10 10:30:00', '2026-06-12 11:00:00');

    $range = ['tab' => 'doctor', 'start' => '2026-06-10', 'end' => '2026-06-11'];

    $this->actingAs($owner)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $doctor->id)
            ->where('breakdown.data.0.amount', '400.00')
            ->where('breakdown.data.0.count', 1)
        );

    vreVoid($owner, $treatment);

    // The appointment the doctor worked still counts, so the row survives — but with no
    // money and no completed treatment behind it.
    $this->actingAs($owner)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $doctor->id)
            ->where('breakdown.data.0.amount', '0.00')
            ->where('breakdown.data.0.count', 0)
            ->where('breakdown.data.0.appointment_count', 1)
            ->where('breakdown.totals.amount', '0.00')
            ->where('breakdown.totals.count', 0)
        );
});

it('zeroes a voided treatment out of the appointment type tab while the appointment itself still counts', function (): void {
    $type = AppointmentType::factory()->create();
    ['clinic' => $clinic, 'owner' => $owner, 'appointment' => $appointment, 'treatment' => $treatment] = vreSetup($type);
    $type->update(['clinic_id' => $clinic->id]);
    $appointment->update(['appointment_type_id' => $type->id]);

    vrePayThenRefund($treatment, '400.00', '2026-06-10 10:30:00', '2026-06-12 11:00:00');

    $range = ['tab' => 'appointment_type', 'start' => '2026-06-10', 'end' => '2026-06-11'];

    $this->actingAs($owner)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $type->id)
            ->where('breakdown.data.0.amount', '400.00')
            ->where('breakdown.data.0.count', 1)
        );

    vreVoid($owner, $treatment);

    // The appointment happened — only its money goes away.
    $this->actingAs($owner)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $type->id)
            ->where('breakdown.data.0.amount', '0.00')
            ->where('breakdown.data.0.count', 1)
        );
});

it('drops a voided treatment out of the branch tab amount and settled count', function (): void {
    ['clinic' => $clinicA, 'owner' => $owner, 'treatment' => $treatment] = vreSetup();

    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id]);
    vreRole($owner, 'owner', $clinicB->id);
    Transaction::factory()->manual()->create([
        'clinic_id' => $clinicB->id, 'amount' => '900.00', 'paid_at' => '2026-06-10 12:00:00',
    ]);

    vrePayThenRefund($treatment, '400.00', '2026-06-10 10:30:00', '2026-06-12 11:00:00');

    $range = ['tab' => 'branch', 'start' => '2026-06-10', 'end' => '2026-06-11'];

    $this->actingAs($owner)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page
            ->where('breakdown.totals.amount', '1300.00')
            ->where('breakdown.totals.count', 2)
        );

    vreVoid($owner, $treatment);

    $this->actingAs($owner)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page
            ->where('breakdown.totals.amount', '900.00')
            ->where('breakdown.totals.count', 1)
            ->where('breakdown.data', function ($rows) use ($clinicA): bool {
                $rowA = collect($rows)->firstWhere('id', $clinicA->id);

                return $rowA['amount'] === '0.00' && $rowA['count'] === 0;
            })
        );
});

it('drops a voided treatment out of the expense-owner tab total (no expense of its own)', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = vreSetup();
    vrePayThenRefund($treatment, '400.00', '2026-06-10 10:30:00', '2026-06-12 11:00:00');

    vreVoid($owner, $treatment);

    // The expense tab reads expenses only — a voided treatment must not conjure a row.
    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'expense_owner', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 0)
            ->where('breakdown.totals.amount', '0.00')
        );
});

// ---------------------------------------------------------------------------
// Revenue (ciro)
// ---------------------------------------------------------------------------

it('drops a voided treatment out of the finance revenue total, retroactively', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = vreSetup();
    vrePayThenRefund($treatment, '400.00', '2026-06-10 10:30:00', '2026-06-12 11:00:00');

    // A window holding the payment but not the refund — the figure the Owner decision says
    // must fall retroactively once the treatment is voided.
    $range = ['start' => '2026-06-10', 'end' => '2026-06-11'];

    $this->actingAs($owner)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page->where('revenue.range.total', '400.00'));

    vreVoid($owner, $treatment);

    $this->actingAs($owner)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page->where('revenue.range.total', '0.00'));
});

it('drops a voided treatment out of the all-time revenue feed', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = vreSetup();
    vrePayThenRefund($treatment, '400.00', '2026-06-10 10:30:00', '2026-06-12 11:00:00');

    Transaction::factory()->manual()->create([
        'clinic_id' => $treatment->clinic_id, 'amount' => '150.00', 'paid_at' => '2026-06-10 12:00:00',
    ]);

    vreVoid($owner, $treatment);

    $this->actingAs($owner)
        ->get(route('reports.index', ['entire' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.total', '150.00')
            ->where('revenue.range.by_period', function ($periods): bool {
                // Only the manual-income day survives; the payment/refund days are gone.
                return count($periods) === 1 && $periods[0]['total'] === '150.00';
            })
        );
});

it('keeps a voided treatment out of the dashboard revenue tile', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = vreSetup();

    // Collected yesterday, refunded today — before the void the tile carries the refund as a
    // negative collection, so the voided treatment is visibly present in today's figure.
    vrePayThenRefund(
        $treatment,
        '400.00',
        now()->subDay()->toDateTimeString(),
        now()->toDateTimeString(),
    );

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '250.00',
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('stats.revenue.today_collected', '-150.00'));

    vreVoid($owner, $treatment);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where('stats.revenue.today_collected', '250.00'));
});

// ---------------------------------------------------------------------------
// Patient balance & deletion
// ---------------------------------------------------------------------------

it('drops a voided treatment out of the patient balance map', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = vreSetup();

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where("balances.{$patient->id}", '500.00'));

    vreVoid($owner, $treatment);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where('balances', []));
});

it('lets an otherwise unblocked patient be deleted once their only open balance is voided', function (): void {
    ['owner' => $owner, 'patient' => $patient, 'treatment' => $treatment] = vreSetup();

    $this->actingAs($owner)
        ->delete(route('patients.destroy', $patient))
        ->assertSessionHas('toasts.0.severity', 'warn');

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->toBeNull();

    vreVoid($owner, $treatment);
    $this->flushSession();

    $this->actingAs($owner)
        ->delete(route('patients.destroy', $patient))
        ->assertRedirect(route('patients.index'));

    $this->assertSoftDeleted('patients', ['id' => $patient->id]);
});

it('still blocks deletion when a live completed treatment remains beside the voided one', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'doctor' => $doctor, 'patient' => $patient, 'treatment' => $treatment] = vreSetup();

    $liveAppointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => '2026-06-11 09:00:00',
    ]);
    Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $liveAppointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'total_amount' => '120.00',
        'completed_at' => '2026-06-11 10:00:00',
    ]);

    vreVoid($owner, $treatment);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where("balances.{$patient->id}", '120.00'));

    $this->actingAs($owner)
        ->delete(route('patients.destroy', $patient))
        ->assertSessionHas('toasts.0.severity', 'warn');

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('does not let one clinic void change another clinic figures', function (): void {
    ['owner' => $ownerA, 'doctor' => $doctorA, 'treatment' => $treatmentA] = vreSetup();
    ['clinic' => $clinicB, 'owner' => $ownerB, 'patient' => $patientB, 'doctor' => $doctorB, 'treatment' => $treatmentB] = vreSetup();

    vrePayThenRefund($treatmentA, '400.00', '2026-06-10 10:30:00', '2026-06-12 11:00:00');
    Transaction::factory()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'treatment_id' => $treatmentB->id,
        'amount' => '400.00',
        'paid_at' => '2026-06-10 10:30:00',
    ]);

    vreVoid($ownerA, $treatmentA);

    $range = ['tab' => 'doctor', 'start' => '2026-06-10', 'end' => '2026-06-11'];

    // Clinic B is untouched: same amount, same count, and only its own doctor.
    $this->actingAs($ownerB)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $doctorB->id)
            ->where('breakdown.data.0.amount', '400.00')
            ->where('breakdown.data.0.count', 1)
        );

    $this->actingAs($ownerB)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where("balances.{$patientB->id}", '100.00'));

    // Clinic A sees only its own (now money-less) doctor row, never B's 400.00.
    $this->actingAs($ownerA)
        ->get(route('reports.index', $range))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $doctorA->id)
            ->where('breakdown.data.0.amount', '0.00')
            ->where('breakdown.totals.amount', '0.00')
        );
});
