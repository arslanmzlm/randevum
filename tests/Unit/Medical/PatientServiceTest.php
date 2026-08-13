<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\Patient;
use App\Models\Transaction;
use App\Models\Treatment;
use App\Modules\Core\Exceptions\DeletionBlockedException;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Medical\Services\PatientService;
use App\Support\ClinicContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Number;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app(ClinicContext::class)->forget();
});

// ---------------------------------------------------------------------------
// create()
// ---------------------------------------------------------------------------

it('create sets clinic_id from BelongsToClinic (ClinicContext)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $service = app(PatientService::class);

    $patient = $service->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'phone' => '+905312345678',
        'notification_enabled' => true,
        'is_legacy' => false,
    ]);

    expect($patient->clinic_id)->toBe($clinic->id);
});

it('create always forces user_id to null', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $service = app(PatientService::class);

    $patient = $service->create([
        'first_name' => 'Test',
        'last_name' => 'User',
        'phone' => '+905312345678',
        'user_id' => 999,
        'notification_enabled' => true,
        'is_legacy' => false,
    ]);

    expect($patient->user_id)->toBeNull();
});

it('create throws TrashedPhoneConflictException when phone matches a trashed patient', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $trashed = Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'phone' => '+905312345678',
    ]);

    $service = app(PatientService::class);

    expect(fn () => $service->create([
        'first_name' => 'New',
        'last_name' => 'Patient',
        'phone' => '+905312345678',
        'notification_enabled' => true,
        'is_legacy' => false,
    ]))->toThrow(TrashedPhoneConflictException::class);
});

it('TrashedPhoneConflictException carries the trashed patient model', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $trashed = Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'phone' => '+905312345678',
    ]);

    $service = app(PatientService::class);

    try {
        $service->create([
            'first_name' => 'New',
            'last_name' => 'Patient',
            'phone' => '+905312345678',
            'notification_enabled' => true,
            'is_legacy' => false,
        ]);
        $this->fail('Expected TrashedPhoneConflictException was not thrown');
    } catch (TrashedPhoneConflictException $e) {
        expect($e->patient->id)->toBe($trashed->id);
    }
});

it('create does not persist a record when the phone conflicts with a trashed patient', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'phone' => '+905312345678',
    ]);

    $countBefore = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count();

    $service = app(PatientService::class);

    try {
        $service->create([
            'first_name' => 'New',
            'last_name' => 'Patient',
            'phone' => '+905312345678',
            'notification_enabled' => true,
            'is_legacy' => false,
        ]);
    } catch (TrashedPhoneConflictException) {
        // expected
    }

    expect(Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count())->toBe($countBefore);
});

it('create succeeds when no trashed patient has the same phone', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $service = app(PatientService::class);

    $patient = $service->create([
        'first_name' => 'Fresh',
        'last_name' => 'Start',
        'phone' => '+905399999999',
        'notification_enabled' => true,
        'is_legacy' => false,
    ]);

    expect($patient->id)->not->toBeNull()
        ->and($patient->first_name)->toBe('Fresh');
});

// ---------------------------------------------------------------------------
// delete() / restore()
// ---------------------------------------------------------------------------

it('delete soft-deletes the patient', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $service = app(PatientService::class);
    $service->delete($patient);

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->not->toBeNull();
});

it('delete is blocked when the patient has an open case', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => Doctor::factory()->create(['clinic_id' => $clinic->id]),
    ]);

    $service = app(PatientService::class);

    expect(fn () => $service->delete($patient))
        ->toThrow(DeletionBlockedException::class);

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->toBeNull();
});

it('delete is blocked when the patient has a future active appointment', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => Doctor::factory()->create(['clinic_id' => $clinic->id]),
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addMinutes(30),
    ]);

    $service = app(PatientService::class);

    expect(fn () => $service->delete($patient))
        ->toThrow(DeletionBlockedException::class);

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->toBeNull();
});

it('delete is blocked when the patient has an open follow-up', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    FollowUp::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
    ]);

    $service = app(PatientService::class);

    expect(fn () => $service->delete($patient))
        ->toThrow(DeletionBlockedException::class);

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->toBeNull();
});

it('delete is blocked when the patient still owes money, and the message names the balance', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = Appointment::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Completed,
    ]);

    Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'total_amount' => '150.00',
    ]);

    $service = app(PatientService::class);

    try {
        $service->delete($patient);
        $this->fail('Expected DeletionBlockedException was not thrown');
    } catch (DeletionBlockedException $e) {
        expect($e->getMessage())->toContain(Number::currency(150.0, 'TRY', 'tr_TR'));
    }

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->toBeNull();
});

it('delete is blocked when the clinic owes the patient a refund (negative balance)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'amount' => '80.00',
    ]);

    $service = app(PatientService::class);

    expect(fn () => $service->delete($patient))
        ->toThrow(DeletionBlockedException::class);

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->toBeNull();
});

it('delete succeeds when the billed total is fully paid', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $appointment = Appointment::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Completed,
    ]);

    $treatment = Treatment::factory()->completed()->create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'total_amount' => '150.00',
    ]);

    Transaction::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'treatment_id' => $treatment->id,
        'amount' => '150.00',
    ]);

    $service = app(PatientService::class);
    $service->delete($patient);

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->not->toBeNull();
});

it("another clinic's unpaid treatment does not block a deletion", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id]);

    $appointmentB = Appointment::factory()->past()->create([
        'clinic_id' => $clinicB->id,
        'patient_id' => $patientB->id,
        'doctor_id' => $doctorB->id,
        'status' => AppointmentStatus::Completed,
    ]);

    Treatment::factory()->completed()->create([
        'clinic_id' => $clinicB->id,
        'appointment_id' => $appointmentB->id,
        'patient_id' => $patientB->id,
        'doctor_id' => $doctorB->id,
        'total_amount' => '500.00',
    ]);

    app(ClinicContext::class)->set($clinicA->id);

    $service = app(PatientService::class);
    $service->delete($patientA);

    expect(Patient::withoutGlobalScopes()->find($patientA->id)->deleted_at)->not->toBeNull();
});

it('delete succeeds when the case, appointment and follow-up are all resolved', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    CaseRecord::factory()->closed()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'status' => AppointmentStatus::Cancelled,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addMinutes(30),
    ]);

    FollowUp::factory()->done()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
    ]);

    $service = app(PatientService::class);
    $service->delete($patient);

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->not->toBeNull();
});

it('restore clears deleted_at on a soft-deleted patient', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $trashed = Patient::factory()->trashed()->create(['clinic_id' => $clinic->id]);

    $service = app(PatientService::class);
    $service->restore($trashed);

    expect(Patient::withoutGlobalScopes()->find($trashed->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// update()
// ---------------------------------------------------------------------------

it('update mutates the specified fields', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Old',
        'notes' => null,
    ]);

    $service = app(PatientService::class);
    $updated = $service->update($patient, ['first_name' => 'New', 'notes' => 'Some note']);

    expect($updated->first_name)->toBe('New')
        ->and($updated->notes)->toBe('Some note');
});
