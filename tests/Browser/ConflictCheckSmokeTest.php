<?php

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.18 (Randevu çakışma kontrolü / live availability pre-check).
 * Real Chromium via pest-plugin-browser: the appointments/create page must mount, render
 * the new 1.18 UI additions (live availability indicator + selected-day appointments panel),
 * and produce no JavaScript errors.
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only catches
 * *uncaught* window errors. Vue swallows component setup/render errors and leaves <main>
 * as an empty comment. The assertSee() calls on in-body labels plus the non-empty <main>
 * script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('renders the appointment create page with 1.18 ui structure and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    // Assign clinic-scoped owner role (Spatie Teams).
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Give the owner a doctor profile so ownDoctorId is set — doctor_id is pre-filled in the form,
    // which is required to activate the availability probe and the day-schedule panel.
    Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $owner->id,
        'is_active' => true,
    ]);

    $this->actingAs($owner);

    visit('/appointments/create')
        ->assertNoJavascriptErrors()
        // Section heading inside the date/time card — page-body content, not the app shell.
        ->assertSee('Tarih ve Saat')
        // Walk-in toggle label rendered inside the appointment details card.
        ->assertSee('Randevusuz hasta')
        // Submit button rendered at the bottom of the form body.
        ->assertSee('Randevu Oluştur')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('shows the availability indicator and day-schedule panel after selecting a date and time', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Doctor profile on the owner so ownDoctorId is passed and doctor_id is pre-filled.
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $owner->id,
        'is_active' => true,
    ]);

    // Seed a confirmed appointment at next Monday 10:00–10:30 Istanbul so the conflict
    // indicator fires when we pick the same slot in the form.
    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $slotStart = $monday->copy()->setTime(10, 0, 0)->utc();
    $slotEnd = $slotStart->copy()->addMinutes(30);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $slotStart,
        'ends_at' => $slotEnd,
    ]);

    $this->actingAs($owner);

    $mondayDay = (int) $monday->format('j'); // day-of-month, no leading zero

    visit('/appointments/create')
        ->assertNoJavascriptErrors()
        ->assertSee('Tarih ve Saat')
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        // Click next-Monday's cell in the inline PrimeVue DatePicker. Day cells carry
        // data-p-other-month="false" and data-p-disabled="false"; match by day number text.
        // Falls back to the first available non-disabled day if Monday is not found (e.g. next month).
        ->assertScript(
            sprintf(
                '() => {
                    const days = Array.from(document.querySelectorAll(".p-datepicker-day:not(.p-disabled):not(.p-datepicker-other-month)"));
                    const monday = days.find(el => el.textContent.trim() === "%d");
                    const target = monday ?? days[0];
                    if (target) { target.click(); return true; }
                    return false;
                }',
                $mondayDay,
            ),
        )
        // Type the time digits slowly so PrimeVue InputMask intercepts each keystroke and formats
        // them into 10:00 (same technique as PatientSearchSmokeTest typeSlowly usage).
        ->typeSlowly('input[placeholder="SS:DD"]', '1000', 50)
        // Wait for the debounced availability XHR (350 ms debounce + server round-trip).
        ->waitForEvent('networkidle')
        // The availability indicator paragraph must be visible — the text matches one of the three
        // states (checking/available/unavailable). A blank body or Vue mount failure renders no
        // indicator at all, making this assert fail.
        ->assertScript(
            '() => {
                const text = document.body.innerText;
                return text.includes("Müsaitlik kontrol ediliyor") ||
                       text.includes("Bu saat müsait") ||
                       text.includes("Seçilen saatte") ||
                       text.includes("çalışma saatleri");
            }',
        )
        // The selected-day panel must be visible: its heading "Günün Randevuları" is rendered
        // only when doctor + date are both set — a blank component leaves no heading.
        ->assertScript(
            '() => document.body.innerText.includes("Günün Randevuları")',
        )
        ->assertNoJavascriptErrors()
        ->screenshot();
});
