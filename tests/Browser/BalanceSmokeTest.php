<?php

use App\Enums\AppointmentStatus;
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
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.23 (Bakiye görüntüleme). Real Chromium via
 * pest-plugin-browser: patients/Show must render the "Bakiye" section
 * (balance summary + transaction list) and treatments/Show must render
 * the "Ödemeler" section.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors()
 * only catches *uncaught* window errors. Vue swallows component setup/render
 * errors and leaves <main> as an empty comment. The assertSee() calls on
 * in-body labels plus the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function blcRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('renders the patient show page with balance summary and transaction list and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    blcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Kaya',
    ]);

    $startsAt = Carbon::now()->addDays(1);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);
    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Arrived)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);
    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => 500,
        'discount_amount' => 0,
        'total_amount' => 500,
        'status' => TreatmentStatus::Completed,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    // A treatment-bound payment and a standalone (null treatment_id) payment.
    Transaction::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '300.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
        'note' => 'İlk taksit',
        'created_by' => $owner->id,
    ]);
    Transaction::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'amount' => '50.00',
        'payment_method' => PaymentMethod::Card,
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
        'note' => null,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    visit("/patients/{$patient->id}")
        ->assertNoJavascriptErrors()
        // The balance lives on the Finans tab since the page was split into tabs.
        ->click('#patient-tab-finance')
        // Page-body section heading — "Bakiye" (balance.section_title).
        ->assertSee('Bakiye')
        // Summary stat labels inside the Bakiye section.
        ->assertSee('Ödenen')
        // Transaction list section heading inside the Bakiye section.
        ->assertSee('Ödemeler')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the treatment show page with payment list section and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    blcRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->addDays(1);
    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Arrived)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);
    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => 250,
        'discount_amount' => 0,
        'total_amount' => 250,
        'status' => TreatmentStatus::Completed,
        'completed_at' => now(),
        'created_by' => $owner->id,
    ]);

    Transaction::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '150.00',
        'payment_method' => PaymentMethod::Transfer,
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
        'note' => 'Havale',
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    visit("/treatments/{$treatment->id}")
        ->assertNoJavascriptErrors()
        // PageHeader title — in the page body, not the app shell nav.
        ->assertSee('Tedavi Detayı')
        // Clinical record section heading — rendered inside the Show page body.
        ->assertSee('Tedavi Bilgileri')
        // "Ödemeler" section heading — balance.transactions_title in the payment section.
        ->assertSee('Ödemeler')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
