<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Product;
use App\Models\Service;
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
 * Assign a clinic-scoped role (tenant-isolation tests).
 */
function tiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a complete clinic setup: owner + doctor + patient + arrived appointment + draft treatment.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, doctorUser: User, patient: Patient, appointment: Appointment, treatment: Treatment}
 */
function tiClinicSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tiRole($owner, 'owner', $clinic->id);

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

    return compact('clinic', 'owner', 'doctor', 'doctorUser', 'patient', 'appointment', 'treatment');
}

// ---------------------------------------------------------------------------
// Route model binding — ClinicScope enforces tenant isolation at the HTTP layer
// ---------------------------------------------------------------------------

it('clinic A cannot view clinic B treatment (404 via ClinicScope)', function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->get(route('treatments.show', $setupB['treatment']))
        ->assertNotFound();
});

it('clinic A cannot open the process screen for clinic B treatment (404)', function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->get(route('treatments.process', $setupB['treatment']))
        ->assertNotFound();
});

it('clinic A cannot complete clinic B treatment (404)', function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    $this->actingAs($setupA['owner'])
        ->put(route('treatments.complete', $setupB['treatment']), [
            'case_mode' => 'none',
            'follow_up' => ['mode' => 'none'],
        ])
        ->assertNotFound();

    // B's treatment must remain Draft
    $fresh = Treatment::withoutGlobalScopes()->find($setupB['treatment']->id);
    expect($fresh->status)->toBe(TreatmentStatus::Draft);
});

it('clinic A cannot start a treatment from clinic B appointment (404)', function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    // Reset appointment B to Confirmed so it's startable if visible
    Appointment::withoutGlobalScopes()
        ->where('id', $setupB['appointment']->id)
        ->update(['status' => AppointmentStatus::Confirmed->value]);

    $this->actingAs($setupA['owner'])
        ->post(route('treatments.start', $setupB['appointment']))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// Process screen — openCases tenant isolation
// ---------------------------------------------------------------------------

it("clinic B's open cases do not appear in clinic A's process screen openCases", function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    // Create an open case in clinic B
    CaseRecord::factory()->open()->create([
        'clinic_id' => $setupB['clinic']->id,
        'patient_id' => $setupB['patient']->id,
        'doctor_id' => $setupB['doctor']->id,
        'vertical_id' => $setupB['clinic']->vertical_id,
    ]);

    // Also create an open case in clinic A (same patient + doctor as A's treatment)
    $caseA = CaseRecord::factory()->open()->create([
        'clinic_id' => $setupA['clinic']->id,
        'patient_id' => $setupA['patient']->id,
        'doctor_id' => $setupA['doctor']->id,
        'vertical_id' => $setupA['clinic']->vertical_id,
    ]);

    $this->actingAs($setupA['owner'])
        ->get(route('treatments.process', $setupA['treatment']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('openCases', 1)
            ->where('openCases.0.id', $caseA->id)
        );
});

// ---------------------------------------------------------------------------
// Complete request — cross-clinic service / product / case validation
// ---------------------------------------------------------------------------

it('rejects a service_id from clinic B in clinic A complete request', function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    $serviceB = Service::factory()->create(['clinic_id' => $setupB['clinic']->id]);

    $this->actingAs($setupA['owner'])
        ->put(route('treatments.complete', $setupA['treatment']), [
            'case_mode' => 'none',
            'follow_up' => ['mode' => 'none'],
            'services' => [
                ['service_id' => $serviceB->id, 'quantity' => 1, 'unit_price' => '50.00'],
            ],
        ])
        ->assertSessionHasErrors('services.0.service_id');
});

it('rejects a product_id from clinic B in clinic A complete request', function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    $productB = Product::factory()->create(['clinic_id' => $setupB['clinic']->id]);

    $this->actingAs($setupA['owner'])
        ->put(route('treatments.complete', $setupA['treatment']), [
            'case_mode' => 'none',
            'follow_up' => ['mode' => 'none'],
            'products' => [
                ['product_id' => $productB->id, 'quantity' => 1, 'unit_price' => '25.00'],
            ],
        ])
        ->assertSessionHasErrors('products.0.product_id');
});

it("rejects clinic B's case_id when using case_mode=existing in clinic A", function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    $caseB = CaseRecord::factory()->open()->create([
        'clinic_id' => $setupB['clinic']->id,
        'patient_id' => $setupB['patient']->id,
        'doctor_id' => $setupB['doctor']->id,
        'vertical_id' => $setupB['clinic']->vertical_id,
    ]);

    $this->actingAs($setupA['owner'])
        ->put(route('treatments.complete', $setupA['treatment']), [
            'case_mode' => 'existing',
            'case_id' => $caseB->id,
            'follow_up' => ['mode' => 'none'],
        ])
        ->assertSessionHasErrors('case_id');
});

// ---------------------------------------------------------------------------
// follow_up.service_id cross-tenant isolation
// ---------------------------------------------------------------------------

it('rejects follow_up.service_id from clinic B and creates no follow-up appointment referencing it', function (): void {
    $setupA = tiClinicSetup();
    $setupB = tiClinicSetup();

    $serviceB = Service::factory()->create(['clinic_id' => $setupB['clinic']->id]);

    $this->actingAs($setupA['owner'])
        ->put(route('treatments.complete', $setupA['treatment']), [
            'case_mode' => 'none',
            'follow_up' => [
                'mode' => 'single',
                'occurrences' => [['starts_at' => Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0)->format('Y-m-d H:i:s')]],
                'service_id' => $serviceB->id,
            ],
        ])
        ->assertSessionHasErrors('follow_up.service_id');

    // Treatment must remain Draft — no side effects committed
    $fresh = Treatment::withoutGlobalScopes()->find($setupA['treatment']->id);
    expect($fresh->status)->toBe(TreatmentStatus::Draft);

    // No follow-up appointment referencing clinic B's service was created
    expect(
        Appointment::withoutGlobalScopes()
            ->where('service_id', $serviceB->id)
            ->exists()
    )->toBeFalse();
});

// ---------------------------------------------------------------------------
// Own/all visibility — doctor cannot view a colleague's treatment without viewAll
// ---------------------------------------------------------------------------

it("doctor A gets 403 on another doctor's treatment (lacks treatments.viewAll)", function (): void {
    $clinic = Clinic::factory()->create();

    // Doctor A
    $doctorUserA = User::factory()->create();
    tiRole($doctorUserA, 'doctor', $clinic->id);
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);

    // Doctor B
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->subHour();
    $appointment = Appointment::factory()
        ->withStatus(AppointmentStatus::Arrived)
        ->create([
            'clinic_id' => $clinic->id,
            'doctor_id' => $doctorB->id,
            'patient_id' => $patient->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
        ]);

    $detail = PodiatryTreatmentDetail::create([]);
    $treatmentB = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctorB->id,
        'details_type' => 'podiatry',
        'details_id' => $detail->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Draft,
        'created_by' => $doctorUserB->id,
    ]);

    // Doctor A (no viewAll) tries to view Doctor B's treatment
    $this->actingAs($doctorUserA)
        ->get(route('treatments.show', $treatmentB))
        ->assertForbidden();
});

it('doctor sees their own treatment (own-record path)', function (): void {
    $clinic = Clinic::factory()->create();

    $doctorUser = User::factory()->create();
    tiRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

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
        'created_by' => $doctorUser->id,
    ]);

    $this->actingAs($doctorUser)
        ->get(route('treatments.show', $treatment))
        ->assertOk();
});

it('owner with treatments.viewAll can see any treatment in the clinic', function (): void {
    $setupA = tiClinicSetup();

    // Owner viewing their clinic's doctor-B treatment (different doctor from the typical owner)
    $this->actingAs($setupA['owner'])
        ->get(route('treatments.show', $setupA['treatment']))
        ->assertOk();
});
