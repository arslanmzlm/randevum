<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PodiatryTreatmentDetail;
use App\Models\Service;
use App\Models\StatusLog;
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
 * Assign a clinic-scoped role (follow-up scheduling tests).
 */
function fuRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a setup with clinic + owner + doctor + patient + arrived appointment + draft treatment.
 *
 * @return array{clinic: Clinic, owner: User, doctor: Doctor, patient: Patient, appointment: Appointment, treatment: Treatment}
 */
function fuSetup(): array
{
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    fuRole($owner, 'owner', $clinic->id);

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
 * The next Monday at 10:00 clinic-local (Europe/Istanbul) — within default working hours.
 */
function fuNextMondaySlot(): string
{
    return Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 10:00:00';
}

/**
 * Minimal complete payload with follow-up fields merged in.
 *
 * @param  array<string, mixed>  $followUp
 * @return array<string, mixed>
 */
function fuPayload(array $followUp = []): array
{
    return array_merge([
        'case_mode' => 'none',
        'follow_up' => array_merge(['mode' => 'none'], $followUp),
    ]);
}

// ---------------------------------------------------------------------------
// Single follow-up
// ---------------------------------------------------------------------------

it('books a single follow-up appointment in confirmed status with a status_log', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor, 'patient' => $patient] = fuSetup();

    $slot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'single',
            'starts_at' => $slot,
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderByDesc('created_at')
        ->get();

    // The first row is the original appointment; the last newly created one is the follow-up
    $followUp = $followUps->first();
    expect($followUp)->not->toBeNull()
        ->and($followUp->status)->toBe(AppointmentStatus::Confirmed);

    $log = StatusLog::withoutGlobalScopes()
        ->where('loggable_type', 'appointment')
        ->where('loggable_id', $followUp->id)
        ->where('from_status', null)
        ->where('to_status', 'confirmed')
        ->first();

    expect($log)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Package: weekly intervals
// ---------------------------------------------------------------------------

it('books a package of weekly follow-ups at 7-day intervals', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor, 'patient' => $patient] = fuSetup();

    $firstSlot = fuNextMondaySlot();
    $firstDate = Carbon::createFromFormat('Y-m-d H:i:s', $firstSlot, 'Europe/Istanbul');

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'starts_at' => $firstSlot,
            'count' => 3,
            'interval' => 'weekly',
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderBy('starts_at')
        ->get();

    // Should have 3 follow-ups
    expect($followUps)->toHaveCount(3);

    // Verify intervals are approximately 7 days
    for ($i = 1; $i < $followUps->count(); $i++) {
        $diff = (int) $followUps[$i - 1]->starts_at->diffInDays($followUps[$i]->starts_at);
        expect($diff)->toBe(7);
    }
});

// ---------------------------------------------------------------------------
// Package: biweekly intervals
// ---------------------------------------------------------------------------

it('books a package of biweekly follow-ups at 14-day intervals', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor, 'patient' => $patient] = fuSetup();

    $firstSlot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'starts_at' => $firstSlot,
            'count' => 2,
            'interval' => 'biweekly',
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderBy('starts_at')
        ->get();

    expect($followUps)->toHaveCount(2);
    expect((int) $followUps[0]->starts_at->diffInDays($followUps[1]->starts_at))->toBe(14);
});

// ---------------------------------------------------------------------------
// Package: monthly intervals (addMonthNoOverflow)
// ---------------------------------------------------------------------------

it('books monthly follow-ups using addMonthNoOverflow semantics', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor, 'patient' => $patient] = fuSetup();

    // Use the next Monday as first slot regardless of month-end edge cases
    $firstSlot = fuNextMondaySlot();
    $firstDate = Carbon::createFromFormat('Y-m-d H:i:s', $firstSlot, 'Europe/Istanbul');
    $expectedSecond = $firstDate->copy()->addMonthNoOverflow();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'starts_at' => $firstSlot,
            'count' => 2,
            'interval' => 'monthly',
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderBy('starts_at')
        ->get();

    expect($followUps)->toHaveCount(2);

    // Second follow-up should be exactly one month after the first (addMonthNoOverflow)
    $firstStartsAt = $followUps[0]->starts_at->setTimezone('Europe/Istanbul');
    $secondStartsAt = $followUps[1]->starts_at->setTimezone('Europe/Istanbul');
    expect($secondStartsAt->format('H:i'))->toBe($firstDate->format('H:i'));
    expect((int) $firstStartsAt->diffInMonths($secondStartsAt))->toBe(1);
});

// ---------------------------------------------------------------------------
// Conflict skipping
// ---------------------------------------------------------------------------

it('skips a conflicting slot and creates the remaining occurrences', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient, 'clinic' => $clinic] = fuSetup();

    $firstSlot = fuNextMondaySlot();
    $firstDate = Carbon::createFromFormat('Y-m-d H:i:s', $firstSlot, 'Europe/Istanbul');

    // Block the SECOND slot (first + 1 week) with a Confirmed appointment
    $secondDate = $firstDate->copy()->addWeek();
    $secondUtc = $secondDate->copy()->utc();

    Appointment::factory()->withStatus(AppointmentStatus::Confirmed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $secondUtc,
        'ends_at' => $secondUtc->copy()->addMinutes(30),
    ]);

    $countBefore = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->count();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'starts_at' => $firstSlot,
            'count' => 3,
            'interval' => 'weekly',
        ]));

    $countAfter = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->count();

    // Occurrences: slot1=created, slot2=skipped (conflict), slot3=created → 2 new follow-ups
    expect($countAfter - $countBefore)->toBe(2);
});

// ---------------------------------------------------------------------------
// Case & service propagation to follow-up appointments
// ---------------------------------------------------------------------------

it('attaches case_id to follow-up appointments when a case is resolved', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient, 'clinic' => $clinic] = fuSetup();

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $slot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'existing',
            'case_id' => $case->id,
            'follow_up' => [
                'mode' => 'single',
                'starts_at' => $slot,
            ],
        ]);

    $followUp = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderByDesc('created_at')
        ->first();

    expect($followUp?->case_id)->toBe($case->id);
});

it('attaches service_id to follow-up appointments when provided', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient, 'clinic' => $clinic] = fuSetup();

    $service = Service::factory()->create(['clinic_id' => $clinic->id]);
    $slot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'single',
            'starts_at' => $slot,
            'service_id' => $service->id,
        ]));

    $followUp = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderByDesc('created_at')
        ->first();

    expect($followUp?->service_id)->toBe($service->id);
});

// ---------------------------------------------------------------------------
// Cap enforcement
// ---------------------------------------------------------------------------

it('caps the follow-up package at 12 sessions regardless of the submitted count', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor, 'patient' => $patient] = fuSetup();

    $slot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'starts_at' => $slot,
            'count' => 12, // max allowed by FormRequest
            'interval' => 'weekly',
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->count();

    expect($followUps)->toBeLessThanOrEqual(12);
});

it('FormRequest rejects follow_up.count above 12', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = fuSetup();

    $slot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'none',
            'follow_up' => [
                'mode' => 'package',
                'starts_at' => $slot,
                'count' => 15,
                'interval' => 'weekly',
            ],
        ])
        ->assertSessionHasErrors('follow_up.count');
});

it('FormRequest rejects follow_up.count below 2 for package mode', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = fuSetup();

    $slot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'none',
            'follow_up' => [
                'mode' => 'package',
                'starts_at' => $slot,
                'count' => 1,
                'interval' => 'weekly',
            ],
        ])
        ->assertSessionHasErrors('follow_up.count');
});
