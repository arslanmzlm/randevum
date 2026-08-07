<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Expense;
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
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

function rbRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array{clinic: Clinic, owner: User}
 */
function rbSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rbRole($owner, 'owner', $clinic->id);

    return compact('clinic', 'owner');
}

// ---------------------------------------------------------------------------
// Tab resolution
// ---------------------------------------------------------------------------

it('defaults to the finance tab and falls back to finance for an unknown tab value', function (): void {
    ['owner' => $owner] = rbSetup();

    $this->actingAs($owner)
        ->get(route('reports.index'))
        ->assertInertia(fn ($page) => $page->where('tab', 'finance')->where('breakdown', null));

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'not-a-real-tab']))
        ->assertInertia(fn ($page) => $page->where('tab', 'finance')->where('breakdown', null));
});

// ---------------------------------------------------------------------------
// Doctor tab
// ---------------------------------------------------------------------------

it('doctor tab reports collected amount, completed count, average and appointment/cancellation rates', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $completedAppointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => '2026-06-10 09:00:00',
        'status' => AppointmentStatus::Completed,
    ]);
    $treatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $completedAppointment->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'completed_at' => '2026-06-10 10:00:00',
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '300.00',
        'paid_at' => '2026-06-10 10:30:00',
    ]);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => '2026-06-11 09:00:00', 'status' => AppointmentStatus::Cancelled,
    ]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => '2026-06-12 09:00:00', 'status' => AppointmentStatus::NoShow,
    ]);

    // Manual income carries no treatment_id — it must never attach to a doctor row.
    Transaction::factory()->manual()->create([
        'clinic_id' => $clinic->id, 'amount' => '999.00', 'paid_at' => '2026-06-10 10:00:00',
    ]);

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'doctor', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->component('reports/Index')
            ->where('tab', 'doctor')
            ->where('revenue', null)
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $doctor->id)
            ->where('breakdown.data.0.amount', '300.00')
            ->where('breakdown.data.0.count', 1)
            ->where('breakdown.data.0.average', '300.00')
            ->where('breakdown.data.0.appointment_count', 3)
            ->where('breakdown.data.0.cancelled_count', 1)
            ->where('breakdown.data.0.no_show_count', 1)
            ->where('breakdown.data.0.cancelled_rate', 33.3)
            ->where('breakdown.data.0.no_show_rate', 33.3)
            ->where('breakdown.totals.amount', '300.00')
            ->where('breakdown.totals.count', 1)
        );
});

it('cancelled/no-show rate denominator excludes future-dated appointments while appointment_count/cancelled_count stay whole-window', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // 2 past appointments (1 cancelled) — the rate's numerator AND denominator.
    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => now()->subDays(2), 'status' => AppointmentStatus::Cancelled,
    ]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => now()->subDay(), 'status' => AppointmentStatus::Confirmed,
    ]);

    // 3 future appointments, none cancelled — must inflate appointment_count but not the rate.
    for ($i = 1; $i <= 3; $i++) {
        Appointment::factory()->create([
            'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
            'starts_at' => now()->addDays($i), 'status' => AppointmentStatus::Confirmed,
        ]);
    }

    $this->actingAs($owner)
        ->get(route('reports.index', [
            'tab' => 'doctor',
            'start' => now()->subWeek()->toDateString(),
            'end' => now()->addWeek()->toDateString(),
        ]))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.appointment_count', 5)
            ->where('breakdown.data.0.cancelled_count', 1)
            ->where('breakdown.data.0.no_show_count', 0)
            // Whole-number rates round-trip through JSON as ints (no fractional part), unlike
            // the fractional 33.3 elsewhere in this file — hence 50/0, not 50.0/0.0.
            ->where('breakdown.data.0.cancelled_rate', 50)
            ->where('breakdown.data.0.no_show_rate', 0)
        );
});

it('a window entirely in the past yields the same rate as before the fix (denominator == the whole window)', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => now()->subDays(10), 'status' => AppointmentStatus::Cancelled,
    ]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'starts_at' => now()->subDays(9), 'status' => AppointmentStatus::Confirmed,
    ]);

    $this->actingAs($owner)
        ->get(route('reports.index', [
            'tab' => 'doctor',
            'start' => now()->subDays(30)->toDateString(),
            'end' => now()->subDays(1)->toDateString(),
        ]))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.appointment_count', 2)
            ->where('breakdown.data.0.cancelled_count', 1)
            ->where('breakdown.data.0.cancelled_rate', 50)
        );
});

// ---------------------------------------------------------------------------
// Service / Product tabs
// ---------------------------------------------------------------------------

it('service tab sums quantity and subtotal from completed treatments only', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $completed = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id, 'completed_at' => '2026-06-10 10:00:00',
    ]);
    TreatmentServiceLine::factory()->create([
        'treatment_id' => $completed->id, 'service_id' => $service->id,
        'quantity' => 2, 'unit_price' => '100.00', 'subtotal' => '200.00',
    ]);

    // Draft treatment's line must not count.
    $draft = Treatment::factory()->draft()->create(['clinic_id' => $clinic->id]);
    TreatmentServiceLine::factory()->create([
        'treatment_id' => $draft->id, 'service_id' => $service->id,
        'quantity' => 5, 'unit_price' => '100.00', 'subtotal' => '500.00',
    ]);

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'service', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $service->id)
            ->where('breakdown.data.0.label', $service->name)
            ->where('breakdown.data.0.amount', '200.00')
            ->where('breakdown.data.0.count', 2)
            ->where('breakdown.data.0.average', '100.00')
        );
});

it('product tab sums quantity/subtotal and still shows a soft-deleted product\'s name', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();
    $product = Product::factory()->create([
        'clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Silinecek Ürün',
    ]);

    $completed = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id, 'completed_at' => '2026-06-10 10:00:00',
    ]);
    TreatmentProductLine::factory()->create([
        'treatment_id' => $completed->id, 'product_id' => $product->id,
        'quantity' => 3, 'unit_price' => '50.00', 'subtotal' => '150.00',
    ]);

    $product->delete();

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'product', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.label', 'Silinecek Ürün')
            ->where('breakdown.data.0.amount', '150.00')
            ->where('breakdown.data.0.count', 3)
        );
});

// ---------------------------------------------------------------------------
// Appointment-type tab
// ---------------------------------------------------------------------------

it('appointment_type tab folds a NULL appointment_type_id into one Unspecified row', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $type = AppointmentType::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $typedAppointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'appointment_type_id' => $type->id, 'starts_at' => '2026-06-10 09:00:00',
    ]);
    $typedTreatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id, 'appointment_id' => $typedAppointment->id,
        'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'completed_at' => '2026-06-10 10:00:00',
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'treatment_id' => $typedTreatment->id,
        'amount' => '400.00', 'paid_at' => '2026-06-10 10:30:00',
    ]);

    // Two untyped appointments fold into a single "Unspecified" row.
    $untypedAppointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'appointment_type_id' => null, 'starts_at' => '2026-06-11 09:00:00',
    ]);
    $untypedTreatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id, 'appointment_id' => $untypedAppointment->id,
        'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'completed_at' => '2026-06-11 10:00:00',
    ]);
    Transaction::factory()->create([
        'clinic_id' => $clinic->id, 'patient_id' => $patient->id, 'treatment_id' => $untypedTreatment->id,
        'amount' => '50.00', 'paid_at' => '2026-06-11 10:30:00',
    ]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id, 'doctor_id' => $doctor->id, 'patient_id' => $patient->id,
        'appointment_type_id' => null, 'starts_at' => '2026-06-12 09:00:00',
    ]);

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'appointment_type', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 2)
            // Default sort -amount: the typed row (400.00) precedes Unspecified (50.00).
            ->where('breakdown.data.0.id', $type->id)
            ->where('breakdown.data.0.amount', '400.00')
            ->where('breakdown.data.0.count', 1)
            ->where('breakdown.data.1.id', null)
            ->where('breakdown.data.1.label', __('report.unspecified'))
            ->where('breakdown.data.1.amount', '50.00')
            ->where('breakdown.data.1.count', 2)
        );
});

// ---------------------------------------------------------------------------
// Expense-owner tab
// ---------------------------------------------------------------------------

it('expense_owner tab counts/sums expenses per creating user', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();
    $staff = User::factory()->create(['first_name' => 'Ayşe', 'last_name' => 'Yılmaz']);

    Expense::factory()->create([
        'clinic_id' => $clinic->id, 'created_by' => $staff->id,
        'expense_date' => '2026-06-10', 'amount' => '100.00',
    ]);
    Expense::factory()->create([
        'clinic_id' => $clinic->id, 'created_by' => $staff->id,
        'expense_date' => '2026-06-11', 'amount' => '50.00',
    ]);

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'expense_owner', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.id', $staff->id)
            ->where('breakdown.data.0.label', 'Ayşe Yılmaz')
            ->where('breakdown.data.0.amount', '150.00')
            ->where('breakdown.data.0.count', 2)
            ->where('breakdown.data.0.average', '75.00')
        );
});

// ---------------------------------------------------------------------------
// Date window
// ---------------------------------------------------------------------------

it('excludes rows outside the date window and includes them with entire=1', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();
    Expense::factory()->create(['clinic_id' => $clinic->id, 'expense_date' => '2025-01-01', 'amount' => '999.00']);

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'expense_owner', 'start' => '2026-06-01', 'end' => '2026-06-30']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 0)
            ->where('breakdown.totals.amount', '0.00')
        );

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'expense_owner', 'entire' => '1']))
        ->assertInertia(fn ($page) => $page
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.amount', '999.00')
        );
});

// ---------------------------------------------------------------------------
// Sort + pagination
// ---------------------------------------------------------------------------

it('sorts by an allowed field, paginates, and totals reflect the whole window not just the page', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();

    // 11 services, quantity 1..11 — one more row than the smallest allowed per_page (10),
    // so ascending-by-count page 2 holds exactly the largest quantity.
    for ($qty = 1; $qty <= 11; $qty++) {
        $service = Service::factory()->create([
            'clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => sprintf('S%02d', $qty),
        ]);
        $treatment = Treatment::factory()->completed()->create(['clinic_id' => $clinic->id, 'completed_at' => '2026-06-10 10:00:00']);
        TreatmentServiceLine::factory()->create([
            'treatment_id' => $treatment->id, 'service_id' => $service->id,
            'quantity' => $qty, 'unit_price' => '1.00', 'subtotal' => number_format($qty, 2, '.', ''),
        ]);
    }

    // Ascending by count, page 2 of size 10 → the single largest quantity (S11, qty 11).
    $this->actingAs($owner)
        ->get(route('reports.index', [
            'tab' => 'service', 'start' => '2026-06-01', 'end' => '2026-06-30',
            'sort' => 'count', 'per_page' => 10, 'page' => 2,
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('breakdown.meta.current_page', 2)
            ->where('breakdown.meta.per_page', 10)
            ->where('breakdown.meta.total', 11)
            ->has('breakdown.data', 1)
            ->where('breakdown.data.0.label', 'S11')
            ->where('breakdown.totals.amount', '66.00')
            ->where('breakdown.totals.count', 66)
        );
});

it('an unknown sort field falls back to the -amount default', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();

    foreach (['Low' => '10.00', 'High' => '90.00'] as $name => $amount) {
        $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => $name]);
        $treatment = Treatment::factory()->completed()->create(['clinic_id' => $clinic->id, 'completed_at' => '2026-06-10 10:00:00']);
        TreatmentServiceLine::factory()->create([
            'treatment_id' => $treatment->id, 'service_id' => $service->id,
            'quantity' => 1, 'unit_price' => $amount, 'subtotal' => $amount,
        ]);
    }

    $this->actingAs($owner)
        ->get(route('reports.index', [
            'tab' => 'service', 'start' => '2026-06-01', 'end' => '2026-06-30', 'sort' => 'not_a_field',
        ]))
        ->assertInertia(fn ($page) => $page
            ->where('breakdown.data.0.label', 'High')
            // The echoed sort must report the resolved default, not the rejected input.
            ->where('query.sort', '-amount')
            ->etc());
});

it('falls back to the default page size when per_page is out of the allow-list', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'service', 'per_page' => 999]))
        ->assertInertia(fn ($page) => $page->where('query.per_page', 20)->etc());
});

it('sorts the doctor tab by a doctor-only field', function (): void {
    ['clinic' => $clinic, 'owner' => $owner] = rbSetup();

    $this->actingAs($owner)
        ->get(route('reports.index', ['tab' => 'doctor', 'sort' => 'cancelled_rate']))
        ->assertInertia(fn ($page) => $page->where('query.sort', 'cancelled_rate')->etc());
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('forbids a receptionist from every report tab', function (string $tab): void {
    ['clinic' => $clinic] = rbSetup();
    $receptionist = User::factory()->create();
    rbRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('reports.index', ['tab' => $tab]))
        ->assertForbidden();
})->with(['finance', 'doctor', 'service', 'product', 'appointment_type', 'expense_owner']);
