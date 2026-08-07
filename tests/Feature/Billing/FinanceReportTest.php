<?php

use App\Enums\PaymentMethod;
use App\Enums\TransactionStatus;
use App\Models\Clinic;
use App\Models\Expense;
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

function frRole(User $user, string $role, int $clinicId): void
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
function frSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    frRole($owner, 'owner', $clinic->id);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'owner', 'patient');
}

function frPayment(Clinic $clinic, Patient $patient, string $amount, PaymentMethod $method, string $paidAtUtc, TransactionStatus $status = TransactionStatus::Completed): Transaction
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

function frExpense(Clinic $clinic, string $amount, string $expenseDate, ?string $category = null): Expense
{
    return Expense::factory()->create([
        'clinic_id' => $clinic->id,
        'amount' => $amount,
        'expense_date' => $expenseDate,
        'category' => $category,
    ]);
}

function frManualIncome(Clinic $clinic, string $amount, string $paidAtUtc, ?string $category = null): Transaction
{
    return Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id,
        'amount' => $amount,
        'category' => $category,
        'paid_at' => CarbonImmutable::parse($paidAtUtc, 'UTC'),
    ]);
}

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('renders the finance report for an owner', function (): void {
    ['owner' => $owner] = frSetup();

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('reports/Index')
            ->where('breakdown', null)
            ->has('revenue.summary')
            ->has('revenue.range.by_method')
            ->has('revenue.range.by_period')
            ->has('expense.total')
            ->has('expense.by_category')
            ->has('net')
            ->has('filters')
        );
});

it('allows a manager to view the finance report', function (): void {
    ['clinic' => $clinic] = frSetup();
    $manager = User::factory()->create();
    frRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('reports.index'))
        ->assertOk();
});

it('forbids a receptionist from the finance report and never exposes revenue or net', function (): void {
    ['clinic' => $clinic] = frSetup();
    $receptionist = User::factory()->create();
    frRole($receptionist, 'receptionist', $clinic->id);

    $response = $this->actingAs($receptionist)->get(route('reports.index'));

    $response->assertForbidden();
    expect($response->getContent())
        ->not->toContain('"revenue"')
        ->not->toContain('"net"');
});

// ---------------------------------------------------------------------------
// Totals & summary (revenue side, unchanged behavior)
// ---------------------------------------------------------------------------

it('sums today and this-month collections net of refunds', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    // Today (Istanbul 2026-06-15): 200 paid, 50 refunded → net 150.
    frPayment($clinic, $patient, '200.00', PaymentMethod::Cash, '2026-06-15 09:00:00');
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '-50.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::PartiallyRefunded,
        'paid_at' => CarbonImmutable::parse('2026-06-15 10:00:00', 'UTC'),
    ]);

    // Earlier this month, not today.
    frPayment($clinic, $patient, '300.00', PaymentMethod::Card, '2026-06-02 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.summary.today', '150.00')
            ->where('revenue.summary.this_month', '450.00')
        );
});

it('excludes pending transactions from collected totals', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-15 09:00:00');
    frPayment($clinic, $patient, '999.00', PaymentMethod::Cash, '2026-06-15 09:00:00', TransactionStatus::Pending);

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('revenue.summary.today', '100.00'));
});

// ---------------------------------------------------------------------------
// Range breakdown (revenue side, unchanged behavior)
// ---------------------------------------------------------------------------

it('breaks the selected range down by payment method in enum order', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '100.00', PaymentMethod::Card, '2026-06-10 09:00:00');
    frPayment($clinic, $patient, '50.00', PaymentMethod::Cash, '2026-06-10 09:00:00');
    frPayment($clinic, $patient, '25.00', PaymentMethod::Cash, '2026-06-11 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-10', 'end' => '2026-06-11']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.total', '175.00')
            ->where('revenue.range.by_method.0.method', 'cash')
            ->where('revenue.range.by_method.0.total', '75.00')
            ->where('revenue.range.by_method.1.method', 'card')
            ->where('revenue.range.by_method.1.total', '100.00')
        );
});

it('buckets the range by clinic-local day', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    // UTC 2026-06-10 22:00 is 2026-06-11 01:00 in Istanbul → bucketed under the 11th.
    frPayment($clinic, $patient, '80.00', PaymentMethod::Cash, '2026-06-10 22:00:00');
    frPayment($clinic, $patient, '20.00', PaymentMethod::Cash, '2026-06-11 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-11', 'end' => '2026-06-11']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.granularity', 'day')
            ->has('revenue.range.by_period', 1)
            ->where('revenue.range.by_period.0.period', '2026-06-11')
            ->where('revenue.range.by_period.0.total', '100.00')
        );
});

it('respects the start/end range filter', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-05 09:00:00');
    frPayment($clinic, $patient, '200.00', PaymentMethod::Cash, '2026-06-20 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-18', 'end' => '2026-06-25']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.total', '200.00')
            ->has('revenue.range.by_period', 1)
        );
});

// ---------------------------------------------------------------------------
// Tenant isolation (revenue side, unchanged behavior)
// ---------------------------------------------------------------------------

it('never counts another clinic transactions', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();
    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-15 09:00:00');

    $otherClinic = Clinic::factory()->create();
    $otherPatient = Patient::factory()->create(['clinic_id' => $otherClinic->id]);
    frPayment($otherClinic, $otherPatient, '5000.00', PaymentMethod::Cash, '2026-06-15 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('revenue.summary.today', '100.00'));
});

// ---------------------------------------------------------------------------
// All-time & adaptive granularity (revenue side, unchanged behavior)
// ---------------------------------------------------------------------------

it('totals all settled transactions for an all-time view', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2024-01-10 09:00:00');
    frPayment($clinic, $patient, '250.00', PaymentMethod::Card, '2026-06-15 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index', ['entire' => '1']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.entire', true)
            ->where('revenue.range.total', '350.00')
        );
});

it('buckets long spans by month instead of day', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-01-10 09:00:00');
    frPayment($clinic, $patient, '40.00', PaymentMethod::Cash, '2026-01-20 09:00:00');
    frPayment($clinic, $patient, '60.00', PaymentMethod::Cash, '2026-03-05 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-01-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.granularity', 'month')
            ->has('revenue.range.by_period', 2)
            ->where('revenue.range.by_period.0.period', '2026-01')
            ->where('revenue.range.by_period.0.total', '140.00')
            ->where('revenue.range.by_period.1.period', '2026-03')
            ->where('revenue.range.by_period.1.total', '60.00')
        );
});

// ---------------------------------------------------------------------------
// Caching & cache clearing (revenue leg only; expense side is always live)
// ---------------------------------------------------------------------------

it('serves a cached revenue figure and only recomputes after the cache is cleared', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();
    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-15 09:00:00');

    // First view computes & caches.
    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('revenue.summary.today', '100.00'));

    // A new payment lands but the cached figure holds until cleared.
    frPayment($clinic, $patient, '50.00', PaymentMethod::Cash, '2026-06-15 10:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('revenue.summary.today', '100.00'));

    $this->actingAs($owner)
        ->post(route('reports.clear-cache'))
        ->assertRedirect();

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('revenue.summary.today', '150.00'));
});

it('forbids a receptionist from clearing the finance cache', function (): void {
    ['clinic' => $clinic] = frSetup();
    $receptionist = User::factory()->create();
    frRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('reports.clear-cache'))
        ->assertForbidden();
});

it('an expense recorded after caching is still reflected live (expense side is never cached)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();
    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-15 09:00:00');

    // Prime the revenue cache.
    $this->actingAs($owner)->get(route('reports.index'));

    frExpense($clinic, '30.00', '2026-06-15');

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('expense.total', '30.00'));
});

// ---------------------------------------------------------------------------
// Manual income — split from patient collections, by-category breakdown
// ---------------------------------------------------------------------------

it('income total is patient collections plus manual income, split apart in the range', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '500.00', PaymentMethod::Cash, '2026-06-10 09:00:00');
    frManualIncome($clinic, '150.00', '2026-06-10 09:00:00', 'Kira geliri');
    frManualIncome($clinic, '50.00', '2026-06-10 09:00:00', 'Kurs geliri');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-10', 'end' => '2026-06-10']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.total', '700.00')
            ->where('revenue.range.patient_total', '500.00')
            ->where('revenue.range.manual_total', '200.00')
        );
});

it('breaks manual income down by category, largest first, uncategorized mapped to null', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = frSetup();

    frManualIncome($clinic, '100.00', '2026-06-10 09:00:00', 'Kira geliri');
    frManualIncome($clinic, '250.00', '2026-06-11 09:00:00', 'Kurs geliri');
    frManualIncome($clinic, '30.00', '2026-06-11 09:00:00', null);

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-10', 'end' => '2026-06-11']))
        ->assertInertia(fn ($page) => $page
            ->has('revenue.range.manual_by_category', 3)
            ->where('revenue.range.manual_by_category.0.category', 'Kurs geliri')
            ->where('revenue.range.manual_by_category.0.total', '250.00')
            ->where('revenue.range.manual_by_category.2.category', null)
            ->where('revenue.range.manual_by_category.2.total', '30.00')
        );
});

it('honours the date window for the patient/manual split', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-05 09:00:00');
    frManualIncome($clinic, '900.00', '2026-06-05 09:00:00', 'Dışında');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-18', 'end' => '2026-06-25']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.patient_total', '0.00')
            ->where('revenue.range.manual_total', '0.00')
        );
});

it('never counts another clinic\'s manual income toward the split or the cache', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();
    frPayment($clinic, $patient, '100.00', PaymentMethod::Cash, '2026-06-15 09:00:00');

    $otherClinic = Clinic::factory()->create();
    frManualIncome($otherClinic, '9999.00', '2026-06-15 09:00:00');

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.summary.today', '100.00')
        );
});

// ---------------------------------------------------------------------------
// Expense side: totals, breakdown, net, list, filter
// ---------------------------------------------------------------------------

it('computes net as revenue minus expense for the selected window', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '500.00', PaymentMethod::Cash, '2026-06-10 09:00:00');
    frExpense($clinic, '120.00', '2026-06-10');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-10', 'end' => '2026-06-10']))
        ->assertInertia(fn ($page) => $page
            ->where('revenue.range.total', '500.00')
            ->where('expense.total', '120.00')
            ->where('net', '380.00')
        );
});

it('nets negative when expense exceeds revenue for the window', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '50.00', PaymentMethod::Cash, '2026-06-10 09:00:00');
    frExpense($clinic, '200.00', '2026-06-10');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-10', 'end' => '2026-06-10']))
        ->assertInertia(fn ($page) => $page->where('net', '-150.00'));
});

it('breaks the expense window down by category, largest first', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = frSetup();

    frExpense($clinic, '100.00', '2026-06-10', 'Kira');
    frExpense($clinic, '250.00', '2026-06-11', 'Fatura');
    frExpense($clinic, '50.00', '2026-06-11', 'Kira');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-10', 'end' => '2026-06-11']))
        ->assertInertia(fn ($page) => $page
            ->has('expense.by_category', 2)
            ->where('expense.by_category.0.category', 'Fatura')
            ->where('expense.by_category.0.total', '250.00')
            ->where('expense.by_category.1.category', 'Kira')
            ->where('expense.by_category.1.total', '150.00')
        );
});

it('reports the expense total for the window without repeating the list', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = frSetup();

    frExpense($clinic, '10.00', '2026-06-10');
    frExpense($clinic, '20.00', '2026-06-11');
    frExpense($clinic, '30.00', '2025-01-01'); // outside the window

    // The rows themselves live on /expenses; this page only carries the totals + breakdown.
    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->where('expense.total', '30.00')
            ->missing('expenses')
        );
});

it('keeps revenue, expense total and net whole regardless of the category filter', function (): void {
    ['clinic' => $clinic, 'owner' => $owner, 'patient' => $patient] = frSetup();

    frPayment($clinic, $patient, '500.00', PaymentMethod::Cash, '2026-06-10 09:00:00');
    frExpense($clinic, '100.00', '2026-06-10', 'Kira');
    frExpense($clinic, '50.00', '2026-06-10', 'Fatura');

    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-10', 'end' => '2026-06-10', 'category' => 'Kira']))
        ->assertInertia(fn ($page) => $page
            ->where('expense.total', '150.00')
            ->where('net', '350.00')
        );
});

it("never counts another clinic's expenses toward the total", function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = frSetup();
    frExpense($clinic, '40.00', '2026-06-10', 'Kira');

    $otherClinic = Clinic::factory()->create();
    frExpense($otherClinic, '9999.00', '2026-06-10', 'Fatura');

    // Clinic B's expense must not reach the window total.
    $this->actingAs($owner)
        ->get(route('reports.index', ['start' => '2026-06-10', 'end' => '2026-06-10']))
        ->assertInertia(fn ($page) => $page
            ->where('expense.total', '40.00')
            ->where('expense.by_category.0.total', '40.00')
        );
});
