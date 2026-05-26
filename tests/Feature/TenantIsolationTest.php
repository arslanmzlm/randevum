<?php

use App\Models\Clinic;
use App\Models\Patient;
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

it('sees across clinics when no clinic context is set', function () {
    Patient::factory()->create();
    Patient::factory()->create();

    expect(Patient::count())->toBe(2);
});
