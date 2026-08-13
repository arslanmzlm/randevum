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
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
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

function pleRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

function pleTreatment(Clinic $clinic, Patient $patient, Doctor $doctor, float $total, TreatmentStatus $status, ?string $completedAt): Treatment
{
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    return Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => $total,
        'discount_amount' => 0,
        'total_amount' => $total,
        'status' => $status,
        'completed_at' => $completedAt !== null ? CarbonImmutable::parse($completedAt, 'UTC') : null,
        'created_by' => null,
    ]);
}

// ---------------------------------------------------------------------------
// last_visit_at column
// ---------------------------------------------------------------------------

it('exposes the latest completed-treatment time as last_visit_at', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pleRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    pleTreatment($clinic, $patient, $doctor, 100, TreatmentStatus::Completed, '2026-05-01 10:00:00');
    pleTreatment($clinic, $patient, $doctor, 100, TreatmentStatus::Completed, '2026-06-10 14:00:00');

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('patients.data.0.last_visit_at', fn ($v) => str_starts_with((string) $v, '2026-06-10'))
        );
});

it('leaves last_visit_at null when the patient has no completed treatment', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pleRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // A draft treatment is not a visit.
    pleTreatment($clinic, $patient, $doctor, 100, TreatmentStatus::Draft, null);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where('patients.data.0.last_visit_at', null));
});

it('sorts patients by last_visit_at', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pleRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $recent = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Recent']);
    $old = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Old']);

    pleTreatment($clinic, $recent, $doctor, 100, TreatmentStatus::Completed, '2026-06-10 10:00:00');
    pleTreatment($clinic, $old, $doctor, 100, TreatmentStatus::Completed, '2026-01-10 10:00:00');

    $this->actingAs($owner)
        ->get(route('patients.index', ['sort' => '-last_visit_at']))
        ->assertInertia(fn ($page) => $page
            ->where('patients.data.0.id', $recent->id)
            ->where('patients.data.1.id', $old->id)
        );
});

// ---------------------------------------------------------------------------
// balances prop
// ---------------------------------------------------------------------------

it('sends remaining balance (billed − paid) for users who can view transactions', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pleRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    pleTreatment($clinic, $patient, $doctor, 300, TreatmentStatus::Completed, '2026-06-10 10:00:00');
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '100.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page
            ->where("balances.{$patient->id}", '200.00')
        );
});

it('omits square patients from the balances map', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pleRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    pleTreatment($clinic, $patient, $doctor, 150, TreatmentStatus::Completed, '2026-06-10 10:00:00');
    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '150.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
    ]);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where('balances', []));
});

it('omits the balances prop for a user without transactions.viewAny', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    pleRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->missing('balances'));
});

// ---------------------------------------------------------------------------
// Tenant isolation
// ---------------------------------------------------------------------------

it('never bleeds another clinic into last_visit_at or balances', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pleRole($owner, 'owner', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Mine']);

    // Another clinic with the same patient name + financial activity must not leak.
    $other = Clinic::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $other->id]);
    $otherPatient = Patient::factory()->create(['clinic_id' => $other->id]);
    pleTreatment($other, $otherPatient, $otherDoctor, 999, TreatmentStatus::Completed, '2026-06-10 10:00:00');

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page
            ->has('patients.data', 1)
            ->where('patients.data.0.last_visit_at', null)
            ->where('balances', [])
        );
});
