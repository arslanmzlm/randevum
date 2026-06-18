<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Transaction;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
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

    // Fix "now" so the today / this-month summary cards are deterministic. Pin it in UTC
    // (the app timezone): Carbon::parse inherits testNow's tz when parsing the naive datetime
    // strings sqlite stores, so a non-UTC testNow would mis-read paid_at as clinic-local.
    // 09:00 UTC is 12:00 Istanbul — mid-day, so the clinic-local calendar day is unambiguous.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-15 09:00:00', 'UTC'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function rrRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinic: Clinic, owner: User, patient: Patient}
 */
function rrSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rrRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'patient');
}

function rrPayment(Clinic $clinic, Patient $patient, string $amount, PaymentMethod $method, string $paidAtUtc, TransactionStatus $status = TransactionStatus::Completed): Transaction
{
    return Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => $amount,
        'payment_method' => $method,
        'status' => $status,
        'paid_at' => CarbonImmutable::parse($paidAtUtc, 'UTC'),
    ]);
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('renders the revenue report for an owner', function (): void {
    ['owner' => $owner] = rrSetup();

    $this->actingAs($owner)
        ->get(route('reports.revenue'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/Revenue')
            ->has('summary')
            ->has('filters')
            ->has('range.by_method')
            ->has('range.by_period')
        );
});

it('allows a manager to view the revenue report', function (): void {
    ['clinic' => $clinic] = rrSetup();
    $manager = User::factory()->create();
    rrRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('reports.revenue'))
        ->assertOk();
});

it('forbids a receptionist from the revenue report', function (): void {
    ['clinic' => $clinic] = rrSetup();
    $receptionist = User::factory()->create();
    rrRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('reports.revenue'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Totals & summary
// ---------------------------------------------------------------------------

it('sums today and this-month collections net of refunds', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();

    // Today (Istanbul 2026-06-15): 200 paid, 50 refunded → net 150.
    rrPayment($clinic, $patient, '200.00', PaymentMethod::Cash, '2026-06-15 09:00:00');
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '-50.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::PartiallyRefunded,
        'paid_at' => CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'),
    ]);

    // Earlier this month, not today.
    rrPayment($clinic, $patient, '300.00', PaymentMethod::Card, '2026-06-02 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.revenue'))
        ->assertInertia(fn ($page) => $page
            ->where('summary.today', '150.00')
            ->where('summary.this_month', '450.00')
        );
});

it('excludes pending transactions from collected totals', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();

    rrPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-15 09:00:00');
    rrPayment($clinic, $patient, '999.00', PaymentMethod::Cash, '2026-06-15 09:00:00', TransactionStatus::Pending);

    $this->actingAs($owner)
        ->get(route('reports.revenue'))
        ->assertInertia(fn ($page) => $page->where('summary.today', '100.00'));
});

// ---------------------------------------------------------------------------
// Range breakdown
// ---------------------------------------------------------------------------

it('breaks the selected range down by payment method in enum order', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();

    rrPayment($clinic, $patient, '100.00', PaymentMethod::Card, '2026-06-10 09:00:00');
    rrPayment($clinic, $patient, '50.00', PaymentMethod::Cash, '2026-06-10 09:00:00');
    rrPayment($clinic, $patient, '25.00', PaymentMethod::Cash, '2026-06-11 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.revenue', ['start' => '2026-06-10', 'end' => '2026-06-11']))
        ->assertInertia(fn ($page) => $page
            ->where('range.total', '175.00')
            ->where('range.by_method.0.method', 'cash')
            ->where('range.by_method.0.total', '75.00')
            ->where('range.by_method.1.method', 'card')
            ->where('range.by_method.1.total', '100.00')
        );
});

it('buckets the range by clinic-local day', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();

    // UTC 2026-06-10 22:00 is 2026-06-11 01:00 in Istanbul → bucketed under the 11th.
    rrPayment($clinic, $patient, '80.00', PaymentMethod::Cash, '2026-06-10 22:00:00');
    rrPayment($clinic, $patient, '20.00', PaymentMethod::Cash, '2026-06-11 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.revenue', ['start' => '2026-06-11', 'end' => '2026-06-11']))
        ->assertInertia(fn ($page) => $page
            ->where('range.granularity', 'day')
            ->has('range.by_period', 1)
            ->where('range.by_period.0.period', '2026-06-11')
            ->where('range.by_period.0.total', '100.00')
        );
});

it('respects the start/end range filter', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();

    rrPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-05 09:00:00');
    rrPayment($clinic, $patient, '200.00', PaymentMethod::Cash, '2026-06-20 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.revenue', ['start' => '2026-06-18', 'end' => '2026-06-25']))
        ->assertInertia(fn ($page) => $page
            ->where('range.total', '200.00')
            ->has('range.by_period', 1)
        );
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('never counts another clinic transactions', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();
    rrPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-15 09:00:00');

    $otherClinic = Clinic::factory()->create();
    $otherPatient = Patient::factory()->create(['clinic_id' => $otherClinic->id]);
    rrPayment($otherClinic, $otherPatient, '5000.00', PaymentMethod::Cash, '2026-06-15 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.revenue'))
        ->assertInertia(fn ($page) => $page->where('summary.today', '100.00'));
});

// ---------------------------------------------------------------------------
// All-time & adaptive granularity
// ---------------------------------------------------------------------------

it('totals all settled transactions for an all-time view', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();

    rrPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2024-01-10 09:00:00');
    rrPayment($clinic, $patient, '250.00', PaymentMethod::Card, '2026-06-15 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.revenue', ['entire' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('range.entire', true)
            ->where('range.total', '350.00')
        );
});

it('buckets long spans by month instead of day', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();

    rrPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-01-10 09:00:00');
    rrPayment($clinic, $patient, '40.00', PaymentMethod::Cash, '2026-01-20 09:00:00');
    rrPayment($clinic, $patient, '60.00', PaymentMethod::Cash, '2026-03-05 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.revenue', ['start' => '2026-01-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->where('range.granularity', 'month')
            ->has('range.by_period', 2)
            ->where('range.by_period.0.period', '2026-01')
            ->where('range.by_period.0.total', '140.00')
            ->where('range.by_period.1.period', '2026-03')
            ->where('range.by_period.1.total', '60.00')
        );
});

// ---------------------------------------------------------------------------
// Caching & cache clearing
// ---------------------------------------------------------------------------

it('serves a cached report and only recomputes after the cache is cleared', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = rrSetup();
    rrPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-15 09:00:00');

    // First view computes & caches.
    $this->actingAs($owner)
        ->get(route('reports.revenue'))
        ->assertInertia(fn ($page) => $page->where('summary.today', '100.00'));

    // A new payment lands but the cached figure holds until cleared.
    rrPayment($clinic, $patient, '50.00', PaymentMethod::Cash, '2026-06-15 10:00:00');

    $this->actingAs($owner)
        ->get(route('reports.revenue'))
        ->assertInertia(fn ($page) => $page->where('summary.today', '100.00'));

    $this->actingAs($owner)
        ->post(route('reports.revenue.clear'))
        ->assertRedirect();

    $this->actingAs($owner)
        ->get(route('reports.revenue'))
        ->assertInertia(fn ($page) => $page->where('summary.today', '150.00'));
});

it('forbids a receptionist from clearing the revenue cache', function (): void {
    ['clinic' => $clinic] = rrSetup();
    $receptionist = User::factory()->create();
    rrRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('reports.revenue.clear'))
        ->assertForbidden();
});
