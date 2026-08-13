<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.22 (Parçalı ödeme / record-payment). Real Chromium
 * via pest-plugin-browser: the patients/Show and treatments/Show pages must mount,
 * render the "Tahsilat Al" button (permissions are loaded via useCan), show
 * page-body content, and produce no JS errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. Vue swallows component setup/render errors and
 * leaves <main> as an empty comment. The assertSee() calls on in-body labels plus
 * the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function pymRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('renders the patient show page with the record-payment button and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pymRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Selin',
        'last_name' => 'Çelik',
    ]);

    $this->actingAs($owner);

    visit("/patients/{$patient->id}")
        ->assertNoJavascriptErrors()
        // Profile section heading — inside the page body, not the app shell nav.
        ->assertSee('Hasta Bilgileri')
        // Treatment history sits on the Klinik tab since the page was split into tabs.
        ->click('#patient-tab-clinical')
        ->assertSee('Tedavi Geçmişi')
        // "Tahsilat Al" button — rendered by the new 1.22 feature when useCan returns true.
        ->assertSee('Tahsilat Al')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the treatment show page with the record-payment button and totals and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pymRole($owner, 'owner', $clinic->id);

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

    $this->actingAs($owner);

    visit("/treatments/{$treatment->id}")
        ->assertNoJavascriptErrors()
        // PageHeader title — in the page body, not the app shell nav.
        ->assertSee('Tedavi Detayı')
        // Clinical record section heading — rendered inside the Show page body.
        ->assertSee('Tedavi Bilgileri')
        // Totals sidebar label — lives in the summary sidebar, unambiguously in the page body.
        ->assertSee('Ara toplam')
        // "Tahsilat Al" button — rendered by the new 1.22 feature when useCan returns true.
        ->assertSee('Tahsilat Al')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
