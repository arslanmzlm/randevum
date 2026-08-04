<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Treatment;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.10 (Tedavi ekleme / Process ekranı). Real Chromium
 * via pest-plugin-browser: the treatments/Process and treatments/Show pages must
 * mount, render page-body content (not just the app shell), and produce no JS errors.
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

/**
 * Assign a clinic-scoped role.
 */
function tpRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Shared setup: clinic + owner + doctor + patient + appointment.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, appointment: Appointment}
 */
function tpSetup(AppointmentStatus $status = AppointmentStatus::Confirmed): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tpRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->addDays(1);
    $appointment = Appointment::factory()->withStatus($status)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    return compact('clinic', 'owner', 'doctor', 'patient', 'appointment');
}

/**
 * Create a Treatment row directly (bypassing the service layer).
 */
function tpTreatment(
    Clinic $clinic,
    Appointment $appointment,
    Patient $patient,
    Doctor $doctor,
    User $creator,
    TreatmentStatus $status = TreatmentStatus::Draft,
): Treatment {
    $detail = PodiatryTreatmentDetail::create([]);

    return Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => $status,
        'completed_at' => $status === TreatmentStatus::Completed ? now() : null,
        'created_by' => $creator->id,
    ]);
}

it('renders the treatment process page with clinical form sections and no JS errors', function (): void {
    [
        'clinic' => $clinic,
        'owner' => $owner,
        'doctor' => $doctor,
        'patient' => $patient,
        'appointment' => $appointment,
    ] = tpSetup();

    $treatment = tpTreatment($clinic, $appointment, $patient, $doctor, $owner);

    $this->actingAs($owner);

    visit("/treatments/{$treatment->id}/process")
        ->assertNoJavascriptErrors()
        // Page title rendered by PageHeader inside the page body.
        ->assertSee('Tedavi Ekle')
        // ClinicalFieldsSection heading — inside the page component, not the shell.
        ->assertSee('Tedavi Bilgileri')
        // Field labels from ClinicalFieldsSection — unambiguously in the page body.
        ->assertSee('Şikayet')
        ->assertSee('Tedavi Süreci')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the treatment show page with clinical detail sections and no JS errors', function (): void {
    [
        'clinic' => $clinic,
        'owner' => $owner,
        'doctor' => $doctor,
        'patient' => $patient,
        'appointment' => $appointment,
    ] = tpSetup(AppointmentStatus::Arrived);

    $treatment = tpTreatment($clinic, $appointment, $patient, $doctor, $owner, TreatmentStatus::Completed);

    $this->actingAs($owner);

    visit("/treatments/{$treatment->id}")
        ->assertNoJavascriptErrors()
        // PageHeader title — in the page body, not the app shell nav.
        ->assertSee('Tedavi Detayı')
        // Clinical record section heading — rendered by the Show page body.
        ->assertSee('Tedavi Bilgileri')
        // Totals labels in the summary sidebar — uniquely in the page body.
        ->assertSee('Ara toplam')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the follow-up section in package mode with occurrence rows and no JS errors', function (): void {
    /**
     * Feature 1.10b smoke: package mode switches on, date + time trigger the watch,
     * FollowUpSection generates N FollowUpOccurrenceRow components, "Seans tarihleri"
     * heading appears, and per-row availability XHRs fire without JS errors.
     */
    [
        'clinic' => $clinic,
        'owner' => $owner,
        'doctor' => $doctor,
        'patient' => $patient,
        'appointment' => $appointment,
    ] = tpSetup();

    $treatment = tpTreatment($clinic, $appointment, $patient, $doctor, $owner);

    $this->actingAs($owner);

    visit("/treatments/{$treatment->id}/process")
        ->assertNoJavascriptErrors()
        // Basic page-body content renders before any interaction.
        ->assertSee('Tedavi Ekle')
        ->assertSee('Sonraki Randevu')
        ->assertSee('Seans paketi')
        // Click "Seans paketi" label → package mode; open the popup DatePicker; click a day.
        // Combined into one async script so Vue DOM updates settle before each interaction step.
        ->assertScript('async () => {
            const label = Array.from(document.querySelectorAll("label"))
                .find(el => el.textContent.trim() === "Seans paketi");
            if (!label) { return false; }
            label.click();
            // Wait for Vue to re-render the package-mode fields.
            await new Promise(r => setTimeout(r, 200));
            const btn = document.querySelector(".p-datepicker-dropdown");
            if (!btn) { return false; }
            btn.click();
            // Poll until the calendar popup is visible (PrimeVue may animate the panel in).
            for (let i = 0; i < 20; i++) {
                const days = Array.from(
                    document.querySelectorAll(".p-datepicker-day:not(.p-disabled)")
                );
                if (days[0]) { days[0].click(); return true; }
                await new Promise(r => setTimeout(r, 100));
            }
            return false;
        }')
        // Type the start time — only the generator InputMask is present at this point
        // (rows are not yet generated because occurrences stay empty until time is valid).
        // FloatLabel fields carry no placeholder, so target the mask input by class.
        ->typeSlowly('input.p-inputmask', '1000', 50)
        // Wait for the Vue watch to propagate and per-row availability XHRs to settle.
        ->waitForEvent('networkidle')
        // "Seans tarihleri" heading renders only when the occurrence row list is non-empty.
        ->assertSee('Seans tarihleri')
        // Row index label ("{n}. seans") — rendered inside FollowUpOccurrenceRow, not in the shell.
        ->assertScript('() => document.body.innerText.includes(". seans")')
        // Guard against the Vue-swallows-setup-error false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->assertNoJavascriptErrors()
        ->screenshot();
});

it('renders the patient show treatment history with a completed treatment and no JS errors', function (): void {
    // Regression: completed_at is a full ISO instant — formatting it with the date-only
    // (YYYY-MM-DD) helper produced an Invalid Date and crashed the whole page render.
    [
        'clinic' => $clinic,
        'owner' => $owner,
        'doctor' => $doctor,
        'patient' => $patient,
        'appointment' => $appointment,
    ] = tpSetup(AppointmentStatus::Arrived);

    tpTreatment($clinic, $appointment, $patient, $doctor, $owner, TreatmentStatus::Completed);

    $this->actingAs($owner);

    visit("/patients/{$patient->id}")
        ->assertNoJavascriptErrors()
        // Treatment history section heading + the completed item's status tag.
        // Treatment history sits on the Klinik tab since the page was split into tabs.
        ->click('#patient-tab-clinical')
        ->assertSee('Tedavi Geçmişi')
        ->assertSee('Tamamlandı')
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
