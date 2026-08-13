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
 * Browser smoke — Feature 1.28 (İade akışı). Real Chromium via
 * pest-plugin-browser: patients/Show must render the refund action column
 * (Durum + İşlemler) for an owner with a Completed transaction and open the
 * RefundDialog on click; treatments/Show is also sanity-checked.
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

function refRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('renders the patient show page with the status and actions columns and opens the refund dialog on click', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    refRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Selin',
        'last_name' => 'Arslan',
    ]);

    Transaction::create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => null,
        'amount' => '200.00',
        'payment_method' => PaymentMethod::Cash,
        'status' => TransactionStatus::Completed,
        'paid_at' => now(),
        'note' => 'Nakit ödeme',
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    visit("/patients/{$patient->id}")
        ->assertNoJavascriptErrors()
        // The balance lives on the Finans tab since the page was split into tabs.
        ->click('#patient-tab-finance')
        // Page-body section headings — rendered inside the Bakiye section.
        ->assertSee('Bakiye')
        ->assertSee('Ödenen')
        ->assertSee('Ödemeler')
        // New Status column header — added by feature 1.28 to TransactionList.
        ->assertSee('Durum')
        // TransactionStatusTag renders 'Tamamlandı' for a Completed row.
        ->assertSee('Tamamlandı')
        // New Actions column header — rendered only for owners (canRefund).
        ->assertSee('İşlemler')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // The refund icon button must be present (aria-label = refund.button).
        ->assertScript(
            '() => !!document.querySelector("[aria-label=\"İade et\"]")',
        )
        // Click the refund button to open the RefundDialog — verifies client-side Vue state.
        ->click('[aria-label="İade et"]')
        // Dialog title 'İade Yap' should appear in the DOM.
        ->assertSee('İade Yap')
        // Both form field labels inside the dialog must render.
        ->assertSee('İade tutarı')
        ->assertSee('İade nedeni')
        ->assertNoJavascriptErrors()
        ->screenshot();
});

it('renders the treatment show page with the status and actions columns and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    refRole($owner, 'owner', $clinic->id);

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
        'subtotal_amount' => 300,
        'discount_amount' => 0,
        'total_amount' => 300,
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
        // Payment section heading.
        ->assertSee('Ödemeler')
        // New Status column + TransactionStatusTag for the Completed transaction.
        ->assertSee('Durum')
        ->assertSee('Tamamlandı')
        // New Actions column — visible for owner.
        ->assertSee('İşlemler')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
