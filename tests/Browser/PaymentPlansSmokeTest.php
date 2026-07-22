<?php

use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Treatment;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.38 (Taksit planı). Real Chromium via
 * pest-plugin-browser: the payment-plans/installments collections page must
 * mount, render page-body content (not just the app shell), and produce no
 * JavaScript errors.
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

it('renders the pending installments collections page with a row and no JS errors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'last_name' => 'Demir',
    ]);

    $plan = PaymentPlan::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
    ]);

    PaymentPlanInstallment::factory()->create([
        'clinic_id' => $clinic->id,
        'payment_plan_id' => $plan->id,
        'sequence' => 1,
        'due_date' => now()->addDays(3)->toDateString(),
    ]);

    $this->actingAs($owner);

    visit('/payment-plans/installments')
        ->waitForEvent('networkidle')
        ->assertNoJavascriptErrors()
        // Page subtitle rendered by PageHeader — inside the page body, not the app shell.
        ->assertSee('Vadesi gelen ve geçen taksitleri takip edin, tahsil edin veya hatırlatma gönderin.')
        // DataTable column header rendered inside the page body.
        ->assertSee('Vade')
        // Patient name rendered inside a DataTable row.
        ->assertSee('Ayşe Demir')
        // Per-row action label rendered inside the page body.
        ->assertSee('Tahsil et')
        // Guard against the silent-blank-body false green: <main> must be non-empty.
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the installment builder in treatment Process with generated rows and no JS errors', function (): void {
    // Exercises real client-side logic (not just render): switching PaymentSection to
    // "installment" mode must drive InstallmentBuilder's watcher to generate schedule rows
    // and a live sum-check banner — this behaviour lives only in the browser, not HTTP tests.
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->addDay();
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $detail = PodiatryTreatmentDetail::create([]);
    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Draft,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner);

    visit("/treatments/{$treatment->id}/process")
        ->assertNoJavascriptErrors()
        ->assertSee('Tedavi Ekle')
        // Click the "Taksit planı" radio option in PaymentSection's ModeSelectRow.
        ->click('#payment-mode-installment')
        ->waitForEvent('networkidle')
        // Builder field + generated schedule rows rendered inside the page body.
        ->assertSee('Taksit sayısı')
        // Sum-check banner only renders once installments.length > 0 — proves the watcher fired.
        ->assertScript('() => document.body.innerText.includes("Taksitler + peşinat")')
        ->assertNoJavascriptErrors()
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
