<?php

use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Tag;
use App\Models\Treatment;
use App\Modules\Medical\Repositories\PatientRepository;
use App\Support\ClinicContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app(ClinicContext::class)->forget();
    request()->replace([]);
});

/**
 * Create a Completed treatment (a "visit") for the patient at the given completed_at (UTC).
 */
function prfVisit(Clinic $clinic, Patient $patient, Doctor $doctor, string $completedAt): Treatment
{
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
    ]);

    return Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => 100,
        'discount_amount' => 0,
        'total_amount' => 100,
        'status' => TreatmentStatus::Completed,
        'completed_at' => CarbonImmutable::parse($completedAt, 'UTC'),
        'created_by' => null,
    ]);
}

// ---------------------------------------------------------------------------
// applyTagFilter() — filter[tags], OR semantics
// ---------------------------------------------------------------------------

it('applyTagFilter matches a patient holding any of the requested tag ids', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    $tagA = Tag::factory()->create(['clinic_id' => $clinic->id]);
    $tagB = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $patientA = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $patientA->tags()->attach($tagA);
    $patientNone = Patient::factory()->create(['clinic_id' => $clinic->id]);

    request()->replace(['filter' => ['tags' => "{$tagA->id},{$tagB->id}"]]);

    $result = app(PatientRepository::class)->paginateForActiveClinic('UTC');

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->id)->toBe($patientA->id);
});

it('applyTagFilter drops non-numeric members and is a no-op when nothing remains', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(2)->create(['clinic_id' => $clinic->id]);

    request()->replace(['filter' => ['tags' => 'not-numeric,also-bad']]);

    $result = app(PatientRepository::class)->paginateForActiveClinic('UTC');

    expect($result->total())->toBe(2);
});

it('applyTagFilter is a no-op when filter[tags] is absent', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(2)->create(['clinic_id' => $clinic->id]);

    request()->replace([]);

    $result = app(PatientRepository::class)->paginateForActiveClinic('UTC');

    expect($result->total())->toBe(2);
});

// ---------------------------------------------------------------------------
// applyLastVisitFilter() — filter[last_visit_after] / filter[last_visit_before]
// ---------------------------------------------------------------------------

it('applyLastVisitFilter "after" matches only patients visited on/after the date', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $recent = Patient::factory()->create(['clinic_id' => $clinic->id]);
    prfVisit($clinic, $recent, $doctor, '2026-06-10 10:00:00');

    $old = Patient::factory()->create(['clinic_id' => $clinic->id]);
    prfVisit($clinic, $old, $doctor, '2025-01-10 10:00:00');

    request()->replace(['filter' => ['last_visit_after' => '2026-01-01']]);

    $result = app(PatientRepository::class)->paginateForActiveClinic('UTC');

    expect($result->total())->toBe(1)
        ->and($result->items()[0]->id)->toBe($recent->id);
});

it('applyLastVisitFilter "before" includes never-visited patients (GATE-1 OPEN-3)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id]);

    $recent = Patient::factory()->create(['clinic_id' => $clinic->id]);
    prfVisit($clinic, $recent, $doctor, '2026-06-10 10:00:00');

    $lapsed = Patient::factory()->create(['clinic_id' => $clinic->id]);
    prfVisit($clinic, $lapsed, $doctor, '2025-01-10 10:00:00');

    $neverVisited = Patient::factory()->create(['clinic_id' => $clinic->id]);

    request()->replace(['filter' => ['last_visit_before' => '2026-01-01']]);

    $result = app(PatientRepository::class)->paginateForActiveClinic('UTC');
    $ids = collect($result->items())->pluck('id')->sort()->values()->all();

    expect($result->total())->toBe(2)
        ->and($ids)->toBe(collect([$lapsed->id, $neverVisited->id])->sort()->values()->all());
});

it('applyLastVisitFilter is a no-op when neither bound is present', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    request()->replace([]);

    $result = app(PatientRepository::class)->paginateForActiveClinic('UTC');

    expect($result->total())->toBe(3);
});
