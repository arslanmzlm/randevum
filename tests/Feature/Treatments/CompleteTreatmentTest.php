<?php

use App\Enums\AppointmentStatus;
use App\Enums\CaseStatus;
use App\Enums\TransactionStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Product;
use App\Models\Service;
use App\Models\StatusLog;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\Carbon;
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

/**
 * Assign a clinic-scoped role (complete-treatment tests).
 */
function ctRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a full setup with an Arrived appointment + Draft treatment, ready for completion.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, appointment: Appointment, treatment: Treatment}
 */
function ctSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ctRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
    ]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->subHour();
    $appointment = Appointment::factory()
        ->withStatus(AppointmentStatus::Arrived)
        ->create([
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

    return compact('clinic', 'owner', 'doctor', 'patient', 'appointment', 'treatment');
}

/**
 * Minimal valid payload for PUT /treatments/{treatment}/complete.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ctPayload(array $overrides = []): array
{
    return array_merge([
        'case_mode' => 'none',
        'follow_up' => ['mode' => 'none'],
    ], $overrides);
}

// ---------------------------------------------------------------------------
// Happy path — core completion behavior
// ---------------------------------------------------------------------------

it('transitions the treatment from draft to completed with completed_at set', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload())
        ->assertRedirect(route('treatments.show', $treatment));

    $fresh = Treatment::withoutGlobalScopes()->find($treatment->id);
    expect($fresh->status)->toBe(TreatmentStatus::Completed)
        ->and($fresh->completed_at)->not->toBeNull();
});

it('writes a draft → completed status_log with the actor', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload());

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'treatment')
        ->where('loggable_id', $treatment->id)
        ->where('from_status', 'draft')
        ->where('to_status', 'completed')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->by_user_id)->toBe($owner->id);
});

it('transitions the appointment from arrived to completed', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'appointment' => $appointment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload());

    $fresh = Appointment::withoutGlobalScopes()->find($appointment->id);
    expect($fresh->status)->toBe(AppointmentStatus::Completed);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $appointment->id)
        ->where('from_status', 'arrived')
        ->where('to_status', 'completed')
        ->first();

    expect($log)->not->toBeNull();
});

it('sets updated_by on the treatment', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload());

    $fresh = Treatment::withoutGlobalScopes()->find($treatment->id);
    expect($fresh->updated_by)->toBe($owner->id);
});

// ---------------------------------------------------------------------------
// Line items: unit_price snapshot + totals math
// ---------------------------------------------------------------------------

it('stores the submitted unit_price as the snapshot, not the catalog price', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic] = ctSetup();

    $service = Service::factory()->create([
        'clinic_id' => $clinic->id,
        'price' => '100.00',
    ]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'services' => [
                ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '150.00'],
            ],
        ]));

    $line = Treatment::withoutGlobalScopes()->find($treatment->id)->serviceLines()->first();
    expect((float) $line->unit_price)->toBe(150.0);
});

it('computes line subtotals correctly: qty × unit_price − line_discount, clamped at 0', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic] = ctSetup();

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'price' => '100.00']);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'services' => [
                ['service_id' => $service->id, 'quantity' => 2, 'unit_price' => '100.00', 'discount_amount' => '50.00'],
            ],
        ]));

    $line = Treatment::withoutGlobalScopes()->find($treatment->id)->serviceLines()->first();
    // 2 × 100 - 50 = 150
    expect((float) $line->subtotal)->toBe(150.0);
});

it('clamps line subtotal at 0 when discount exceeds price', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic] = ctSetup();

    $service = Service::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'services' => [
                ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '50.00', 'discount_amount' => '100.00'],
            ],
        ]));

    $line = Treatment::withoutGlobalScopes()->find($treatment->id)->serviceLines()->first();
    expect((float) $line->subtotal)->toBe(0.0);
});

it('computes treatment-level totals: subtotal = sum of line subtotals, total = max(0, subtotal − discount)', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic] = ctSetup();

    $svc1 = Service::factory()->create(['clinic_id' => $clinic->id]);
    $svc2 = Service::factory()->create(['clinic_id' => $clinic->id]);

    // Line 1: 2 × 80 = 160; Line 2: 1 × 40 = 40 → subtotal = 200; treatment discount = 30 → total = 170
    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'services' => [
                ['service_id' => $svc1->id, 'quantity' => 2, 'unit_price' => '80.00'],
                ['service_id' => $svc2->id, 'quantity' => 1, 'unit_price' => '40.00'],
            ],
            'discount_amount' => '30.00',
        ]));

    $fresh = Treatment::withoutGlobalScopes()->find($treatment->id);
    expect((float) $fresh->subtotal_amount)->toBe(200.0)
        ->and((float) $fresh->discount_amount)->toBe(30.0)
        ->and((float) $fresh->total_amount)->toBe(170.0);
});

it('clamps treatment total at 0 when treatment discount exceeds subtotal', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic] = ctSetup();

    $service = Service::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'services' => [
                ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '50.00'],
            ],
            'discount_amount' => '200.00',
        ]));

    $fresh = Treatment::withoutGlobalScopes()->find($treatment->id);
    expect((float) $fresh->total_amount)->toBe(0.0);
});

// ---------------------------------------------------------------------------
// Stock deduction
// ---------------------------------------------------------------------------

it('deducts current_stock for each product line when completing', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic] = ctSetup();

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'current_stock' => 10]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'products' => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => '25.00'],
            ],
        ]));

    $fresh = Product::withoutGlobalScopes()->find($product->id);
    expect($fresh->current_stock)->toBe(7);
});

it('allows stock to go negative when deduction exceeds available stock', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic] = ctSetup();

    $product = Product::factory()->create(['clinic_id' => $clinic->id, 'current_stock' => 2]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'products' => [
                ['product_id' => $product->id, 'quantity' => 5, 'unit_price' => '25.00'],
            ],
        ]));

    $fresh = Product::withoutGlobalScopes()->find($product->id);
    expect($fresh->current_stock)->toBe(-3);
});

// ---------------------------------------------------------------------------
// Optional payments (may be split across methods)
// ---------------------------------------------------------------------------

it('creates a completed transaction with status_log for a payment row', function (): void {
    ['owner' => $owner, 'clinic' => $clinic, 'treatment' => $treatment, 'patient' => $patient] = ctSetup();
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'price' => '150.00']);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'services' => [
                ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '150.00'],
            ],
            'payments' => [['amount' => '150.00', 'method' => 'cash']],
        ]));

    $tx = Transaction::withoutGlobalScopes()
        ->where('treatment_id', $treatment->id)
        ->first();

    expect($tx)->not->toBeNull()
        ->and($tx->status)->toBe(TransactionStatus::Completed)
        ->and((float) $tx->amount)->toBe(150.0)
        ->and($tx->patient_id)->toBe($patient->id);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'transaction')
        ->where('loggable_id', $tx->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBeNull()
        ->and($log->to_status)->toBe('completed');
});

it('splits a payment across methods into one transaction per row', function (): void {
    ['owner' => $owner, 'clinic' => $clinic, 'treatment' => $treatment] = ctSetup();
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'price' => '200.00']);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'services' => [
                ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '200.00'],
            ],
            'payments' => [
                ['amount' => '120.00', 'method' => 'card'],
                ['amount' => '80.00', 'method' => 'cash'],
            ],
        ]));

    $transactions = Transaction::withoutGlobalScopes()
        ->where('treatment_id', $treatment->id)
        ->orderBy('id')
        ->get();

    expect($transactions)->toHaveCount(2)
        ->and((float) $transactions[0]->amount)->toBe(120.0)
        ->and($transactions[0]->payment_method->value)->toBe('card')
        ->and((float) $transactions[1]->amount)->toBe(80.0)
        ->and($transactions[1]->payment_method->value)->toBe('cash');
});

it('creates no transaction when payments is absent', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload());

    expect(Transaction::withoutGlobalScopes()->where('treatment_id', $treatment->id)->exists())->toBeFalse();
});

it('rejects payments exceeding the treatment total and rolls back completion', function (): void {
    ['owner' => $owner, 'clinic' => $clinic, 'treatment' => $treatment] = ctSetup();
    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'price' => '100.00']);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'services' => [
                ['service_id' => $service->id, 'quantity' => 1, 'unit_price' => '100.00'],
            ],
            'payments' => [['amount' => '150.00', 'method' => 'cash']],
        ]))
        ->assertSessionHasErrors('payments');

    $fresh = Treatment::withoutGlobalScopes()->find($treatment->id);
    expect($fresh->status)->toBe(TreatmentStatus::Draft)
        ->and(Transaction::withoutGlobalScopes()->where('treatment_id', $treatment->id)->exists())->toBeFalse();
});

it('rejects a payment row with amount 0', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'payments' => [['amount' => '0', 'method' => 'cash']],
        ]))
        ->assertSessionHasErrors('payments.0.amount');
});

// ---------------------------------------------------------------------------
// Case linking — case_mode: none
// ---------------------------------------------------------------------------

it('case_mode=none leaves case_id null on treatment and appointment', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'appointment' => $appointment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload(['case_mode' => 'none']));

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    $freshAppointment = Appointment::withoutGlobalScopes()->find($appointment->id);

    expect($freshTreatment->case_id)->toBeNull()
        ->and($freshAppointment->case_id)->toBeNull();
});

// ---------------------------------------------------------------------------
// Case linking — case_mode: existing
// ---------------------------------------------------------------------------

it('case_mode=existing links an open case to the treatment and appointment', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic,
        'patient' => $patient, 'doctor' => $doctor, 'appointment' => $appointment] = ctSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'case_mode' => 'existing',
            'case_id' => $case->id,
        ]));

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    $freshAppointment = Appointment::withoutGlobalScopes()->find($appointment->id);

    expect($freshTreatment->case_id)->toBe($case->id)
        ->and($freshAppointment->case_id)->toBe($case->id);
});

it('rejects case_mode=existing when the case belongs to a different patient', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic, 'doctor' => $doctor] = ctSetup();

    $otherPatient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $otherPatient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'case_mode' => 'existing',
            'case_id' => $case->id,
        ]))
        ->assertSessionHasErrors('case_id');
});

it('rejects case_mode=existing when the case belongs to a different doctor', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic, 'patient' => $patient] = ctSetup();

    $otherDoctorUser = User::factory()->create();
    $otherDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherDoctorUser->id]);
    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $otherDoctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'case_mode' => 'existing',
            'case_id' => $case->id,
        ]))
        ->assertSessionHasErrors('case_id');
});

it('rejects case_mode=existing when the case is closed', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'clinic' => $clinic,
        'patient' => $patient, 'doctor' => $doctor] = ctSetup();

    $case = CaseRecord::factory()->closed()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'case_mode' => 'existing',
            'case_id' => $case->id,
        ]))
        ->assertSessionHasErrors('case_id');
});

// ---------------------------------------------------------------------------
// Case linking — case_mode: new
// ---------------------------------------------------------------------------

it('case_mode=new creates an open case and sets case_id on both treatment and appointment', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'appointment' => $appointment,
        'patient' => $patient, 'doctor' => $doctor] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'case_mode' => 'new',
            'new_case_title' => 'Yeni Vaka',
        ]));

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    $freshAppointment = Appointment::withoutGlobalScopes()->find($appointment->id);

    expect($freshTreatment->case_id)->not->toBeNull()
        ->and($freshAppointment->case_id)->toBe($freshTreatment->case_id);

    $case = CaseRecord::withoutGlobalScopes()->find($freshTreatment->case_id);
    expect($case)->not->toBeNull()
        ->and($case->status)->toBe(CaseStatus::Open)
        ->and($case->title)->toBe('Yeni Vaka')
        ->and($case->patient_id)->toBe($patient->id)
        ->and($case->doctor_id)->toBe($doctor->id);
});

it('writes a null → open status_log for the newly created case', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'case_mode' => 'new',
            'new_case_title' => 'Log Test Vaka',
        ]));

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);
    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'case')
        ->where('loggable_id', $freshTreatment->case_id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->from_status)->toBeNull()
        ->and($log->to_status)->toBe('open');
});

// ---------------------------------------------------------------------------
// Guard: double-complete
// ---------------------------------------------------------------------------

it('returns 422 when trying to complete an already completed treatment', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload());

    // Try to complete again
    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload())
        ->assertSessionHasErrors('treatment');
});

// ---------------------------------------------------------------------------
// Authorization
// ---------------------------------------------------------------------------

it('returns 403 for a receptionist who lacks treatments.create', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = ctSetup();

    $receptionist = User::factory()->create();
    ctRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->put(route('treatments.complete', $treatment), ctPayload())
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Sub-input permission enforcement (server-side guards in withValidator)
// ---------------------------------------------------------------------------

it('rejects payment rows from an assistant who lacks transactions.create', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = ctSetup();

    $assistant = User::factory()->create();
    ctRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'payments' => [['amount' => '100.00', 'method' => 'cash']],
        ]))
        ->assertForbidden();
});

it('rejects case_mode=new from a receptionist who lacks cases.create', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = ctSetup();

    $receptionist = User::factory()->create();
    ctRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'case_mode' => 'new',
            'new_case_title' => 'Test Vaka',
        ]))
        ->assertForbidden();
});

it('rejects follow_up.mode=single from an assistant who lacks appointments.create', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = ctSetup();

    $assistant = User::factory()->create();
    ctRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->put(route('treatments.complete', $treatment), ctPayload([
            'follow_up' => [
                'mode' => 'single',
                'occurrences' => [['starts_at' => Carbon::now()->addWeek()->format('Y-m-d H:i:s')]],
            ],
        ]))
        ->assertForbidden();
});

it('GET /treatments/{treatment} (show) renders the Show component with treatment data', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = ctSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), ctPayload());

    $freshTreatment = Treatment::withoutGlobalScopes()->find($treatment->id);

    $this->actingAs($owner)
        ->get(route('treatments.show', $freshTreatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Show')
            ->has('treatment')
        );
});
