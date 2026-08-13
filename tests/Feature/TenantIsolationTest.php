<?php

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Service;
use App\Scopes\ClinicScope;
use App\Support\ClinicContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    app(ClinicContext::class)->forget();
});

function actAsClinic(Clinic $clinic): void
{
    app(ClinicContext::class)->set($clinic->id);
}

it('scopes reads to the active clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    actAsClinic($clinicA);

    expect(Patient::pluck('id')->all())->toBe([$patientA->id]);
    expect(Patient::find($patientB->id))->toBeNull();
});

it('auto-fills clinic_id from the active clinic on create', function () {
    $clinic = Clinic::factory()->create();
    actAsClinic($clinic);

    $patient = Patient::create([
        'first_name' => 'Test',
        'last_name' => 'Hasta',
        'phone' => '0532 111 22 33',
    ]);

    expect($patient->clinic_id)->toBe($clinic->id);
});

it('blocks updating a record owned by another clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    actAsClinic($clinicA);
    expect(Patient::where('id', $patientB->id)->update(['first_name' => 'Hacked']))->toBe(0);

    actAsClinic($clinicB);
    expect(Patient::find($patientB->id)->first_name)->not->toBe('Hacked');
});

it('blocks deleting a record owned by another clinic', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);

    actAsClinic($clinicA);
    expect(Patient::where('id', $patientB->id)->delete())->toBe(0);

    actAsClinic($clinicB);
    expect(Patient::find($patientB->id))->not->toBeNull();
});

it('returns nothing when no clinic context is set', function () {
    Patient::factory()->create();
    Patient::factory()->create();

    expect(Patient::count())->toBe(0)
        ->and(Patient::get())->toBeEmpty();
});

it('fail-closes every clinic-owned model when no clinic context is set', function () {
    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);
    $service = Service::factory()->create(['clinic_id' => $clinic->id]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'service_id' => $service->id,
    ]);

    app(ClinicContext::class)->forget();

    expect(Patient::count())->toBe(0)
        ->and(Doctor::count())->toBe(0)
        ->and(Service::count())->toBe(0)
        ->and(Appointment::count())->toBe(0)
        ->and(Patient::find($patient->id))->toBeNull();
});

it('sees the clinic rows again once the context is set', function () {
    $clinic = Clinic::factory()->create();
    Patient::factory()->count(2)->create(['clinic_id' => $clinic->id]);

    expect(Patient::count())->toBe(0);

    actAsClinic($clinic);

    expect(Patient::count())->toBe(2);
});

it('lets an explicit withoutGlobalScope opt out of the fail-closed scope', function () {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    Patient::factory()->create(['clinic_id' => $clinicA->id]);
    Patient::factory()->create(['clinic_id' => $clinicB->id]);

    expect(Patient::withoutGlobalScope(ClinicScope::class)->count())->toBe(2);
});
