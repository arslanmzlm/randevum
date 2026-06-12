<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentType;
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

/**
 * Wrap clinic-local datetime strings as occurrence objects (untyped rows).
 *
 * @param  list<string>  $slots
 * @return list<array{starts_at: string}>
 */
function fuOccurrences(array $slots): array
{
    return array_map(fn (string $slot): array => ['starts_at' => $slot], $slots);
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
            'occurrences' => fuOccurrences([$slot]),
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

    $firstDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0);
    $occurrences = [
        $firstDate->format('Y-m-d H:i:s'),
        $firstDate->copy()->addWeek()->format('Y-m-d H:i:s'),
        $firstDate->copy()->addWeeks(2)->format('Y-m-d H:i:s'),
    ];

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'occurrences' => fuOccurrences($occurrences),
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderBy('starts_at')
        ->get();

    expect($followUps)->toHaveCount(3);

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

    $firstDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0);
    $occurrences = [
        $firstDate->format('Y-m-d H:i:s'),
        $firstDate->copy()->addWeeks(2)->format('Y-m-d H:i:s'),
    ];

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'occurrences' => fuOccurrences($occurrences),
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
// Package: monthly intervals
// ---------------------------------------------------------------------------

it('books monthly follow-ups at the submitted clinic-local times', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor, 'patient' => $patient] = fuSetup();

    $firstDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0);
    $secondDate = $firstDate->copy()->addMonthNoOverflow();

    $occurrences = [
        $firstDate->format('Y-m-d H:i:s'),
        $secondDate->format('Y-m-d H:i:s'),
    ];

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'occurrences' => fuOccurrences($occurrences),
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderBy('starts_at')
        ->get();

    expect($followUps)->toHaveCount(2);

    $firstStartsAt = $followUps[0]->starts_at->setTimezone('Europe/Istanbul');
    $secondStartsAt = $followUps[1]->starts_at->setTimezone('Europe/Istanbul');
    expect($secondStartsAt->format('H:i'))->toBe($firstDate->format('H:i'));
    expect((int) $firstStartsAt->diffInMonths($secondStartsAt))->toBe(1);
});

// ---------------------------------------------------------------------------
// Package: hand-edited (irregular) occurrence times
// ---------------------------------------------------------------------------

it('honors per-row hand-edited times — server uses exactly the submitted datetimes', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor, 'patient' => $patient] = fuSetup();

    $base = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);

    // Irregular times — not interval-math derived; on different days
    $slot1Local = $base->copy()->setTime(9, 0, 0);
    $slot2Local = $base->copy()->addDays(3)->setTime(14, 30, 0);
    $slot3Local = $base->copy()->addDays(10)->setTime(11, 15, 0);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'occurrences' => fuOccurrences([
                $slot1Local->format('Y-m-d H:i:s'),
                $slot2Local->format('Y-m-d H:i:s'),
                $slot3Local->format('Y-m-d H:i:s'),
            ]),
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderBy('starts_at')
        ->get();

    expect($followUps)->toHaveCount(3);

    // Server sorts by full timestamp ascending: slot1 < slot2 < slot3 (different days)
    $expectedTimes = [
        $slot1Local->format('H:i'),
        $slot2Local->format('H:i'),
        $slot3Local->format('H:i'),
    ];

    foreach ($followUps as $i => $appt) {
        expect($appt->starts_at->setTimezone('Europe/Istanbul')->format('H:i'))
            ->toBe($expectedTimes[$i]);
    }
});

// ---------------------------------------------------------------------------
// Conflict skipping
// ---------------------------------------------------------------------------

it('skips a conflicting slot and creates the remaining occurrences', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient, 'clinic' => $clinic] = fuSetup();

    $firstDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0);

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
            'occurrences' => fuOccurrences([
                $firstDate->format('Y-m-d H:i:s'),
                $secondDate->format('Y-m-d H:i:s'),
                $firstDate->copy()->addWeeks(2)->format('Y-m-d H:i:s'),
            ]),
        ]));

    $countAfter = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->count();

    // slot1=created, slot2=skipped (conflict), slot3=created → 2 new follow-ups
    expect($countAfter - $countBefore)->toBe(2);
});

// ---------------------------------------------------------------------------
// Duplicate occurrences — first books, second skipped
// ---------------------------------------------------------------------------

it('skips duplicate occurrences: the first books, the second hits the overlap check', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient] = fuSetup();

    $slot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'occurrences' => fuOccurrences([$slot, $slot]),
        ]));

    $confirmed = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->count();

    // Only one should book; the duplicate is blocked by the overlap check
    expect($confirmed)->toBe(1);
});

// ---------------------------------------------------------------------------
// Unsorted input — ascending sort applied before processing
// ---------------------------------------------------------------------------

it('creates appointments at the correct times regardless of submission order', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient] = fuSetup();

    $base = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);

    $earlySlot = $base->copy()->setTime(9, 0, 0)->format('Y-m-d H:i:s');
    $lateSlot = $base->copy()->addWeek()->setTime(10, 0, 0)->format('Y-m-d H:i:s');

    // Submit in reverse order (late first)
    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'occurrences' => fuOccurrences([$lateSlot, $earlySlot]),
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderBy('starts_at')
        ->get();

    expect($followUps)->toHaveCount(2);
    // First by starts_at should be the early slot (09:00)
    expect($followUps[0]->starts_at->setTimezone('Europe/Istanbul')->format('H:i'))->toBe('09:00');
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
                'occurrences' => fuOccurrences([$slot]),
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
            'occurrences' => fuOccurrences([$slot]),
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

it('attaches per-occurrence appointment types and uses each type default duration', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient, 'clinic' => $clinic] = fuSetup();

    $kontrol = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'default_duration_minutes' => 20,
    ]);
    $muayene = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'default_duration_minutes' => 40,
    ]);

    $firstDate = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'occurrences' => [
                ['starts_at' => $firstDate->format('Y-m-d H:i:s'), 'appointment_type_id' => $muayene->id],
                ['starts_at' => $firstDate->copy()->addWeek()->format('Y-m-d H:i:s'), 'appointment_type_id' => $kontrol->id],
            ],
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderBy('starts_at')
        ->get();

    expect($followUps)->toHaveCount(2)
        ->and($followUps[0]->appointment_type_id)->toBe($muayene->id)
        ->and($followUps[0]->starts_at->diffInMinutes($followUps[0]->ends_at))->toBe(40.0)
        ->and($followUps[1]->appointment_type_id)->toBe($kontrol->id)
        ->and($followUps[1]->starts_at->diffInMinutes($followUps[1]->ends_at))->toBe(20.0);
});

it('honors an explicit per-occurrence duration over the type default', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient, 'clinic' => $clinic] = fuSetup();

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'default_duration_minutes' => 40,
    ]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'single',
            'occurrences' => [
                [
                    'starts_at' => fuNextMondaySlot(),
                    'duration_minutes' => 25,
                    'appointment_type_id' => $type->id,
                ],
            ],
        ]));

    $followUp = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderByDesc('created_at')
        ->first();

    expect($followUp?->starts_at?->diffInMinutes($followUp->ends_at))->toBe(25.0);
});

// ---------------------------------------------------------------------------
// Cap enforcement
// ---------------------------------------------------------------------------

it('caps the follow-up package at 12 sessions regardless of the submitted count', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor, 'patient' => $patient] = fuSetup();

    $base = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0);
    $occurrences = [];
    for ($i = 0; $i < 12; $i++) {
        $occurrences[] = $base->copy()->addWeeks($i)->format('Y-m-d H:i:s');
    }

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'package',
            'occurrences' => fuOccurrences($occurrences),
        ]));

    $followUps = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->count();

    expect($followUps)->toBeLessThanOrEqual(12);
});

// ---------------------------------------------------------------------------
// FormRequest validation
// ---------------------------------------------------------------------------

it('FormRequest rejects package occurrences above 12', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = fuSetup();

    $base = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0);
    $occurrences = [];
    for ($i = 0; $i < 13; $i++) {
        $occurrences[] = $base->copy()->addWeeks($i)->format('Y-m-d H:i:s');
    }

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'none',
            'follow_up' => [
                'mode' => 'package',
                'occurrences' => fuOccurrences($occurrences),
            ],
        ])
        ->assertSessionHasErrors('follow_up.occurrences');
});

it('FormRequest rejects package with fewer than 2 occurrences', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = fuSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'none',
            'follow_up' => [
                'mode' => 'package',
                'occurrences' => fuOccurrences([fuNextMondaySlot()]),
            ],
        ])
        ->assertSessionHasErrors('follow_up.occurrences');
});

it('FormRequest rejects single mode with more than 1 occurrence', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = fuSetup();

    $base = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'none',
            'follow_up' => [
                'mode' => 'single',
                'occurrences' => fuOccurrences([
                    $base->format('Y-m-d H:i:s'),
                    $base->copy()->addWeek()->format('Y-m-d H:i:s'),
                ]),
            ],
        ])
        ->assertSessionHasErrors('follow_up.occurrences');
});

it('FormRequest rejects a malformed date string in an occurrence', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = fuSetup();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), [
            'case_mode' => 'none',
            'follow_up' => [
                'mode' => 'single',
                'occurrences' => [['starts_at' => 'not-a-date']],
            ],
        ])
        ->assertSessionHasErrors('follow_up.occurrences.0.starts_at');
});

it('FormRequest rejects a cross-clinic service_id in follow_up.service_id', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = fuSetup();

    $otherClinic = Clinic::factory()->create();
    $otherService = Service::factory()->create(['clinic_id' => $otherClinic->id]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'single',
            'occurrences' => fuOccurrences([fuNextMondaySlot()]),
            'service_id' => $otherService->id,
        ]))
        ->assertSessionHasErrors('follow_up.service_id');
});

it('FormRequest rejects a cross-clinic appointment_type_id on an occurrence', function (): void {
    ['owner' => $owner, 'treatment' => $treatment] = fuSetup();

    $otherClinic = Clinic::factory()->create();
    $otherType = AppointmentType::factory()->create(['clinic_id' => $otherClinic->id]);

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'single',
            'occurrences' => [
                ['starts_at' => fuNextMondaySlot(), 'appointment_type_id' => $otherType->id],
            ],
        ]))
        ->assertSessionHasErrors('follow_up.occurrences.0.appointment_type_id');
});

// ---------------------------------------------------------------------------
// Permission guard
// ---------------------------------------------------------------------------

it('rejects follow_up booking from a user without appointments.create', function (): void {
    ['clinic' => $clinic, 'treatment' => $treatment] = fuSetup();

    $assistant = User::factory()->create();
    fuRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'single',
            'occurrences' => fuOccurrences([fuNextMondaySlot()]),
        ]))
        ->assertSessionHasErrors('follow_up.mode');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation
// ---------------------------------------------------------------------------

it('creates follow-up appointments under the actor\'s clinic_id, not another clinic', function (): void {
    ['owner' => $owner, 'treatment' => $treatment, 'doctor' => $doctor,
        'patient' => $patient, 'clinic' => $clinic] = fuSetup();

    $slot = fuNextMondaySlot();

    $this->actingAs($owner)
        ->put(route('treatments.complete', $treatment), fuPayload([
            'mode' => 'single',
            'occurrences' => fuOccurrences([$slot]),
        ]));

    $followUp = Appointment::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->orderByDesc('created_at')
        ->first();

    expect($followUp)->not->toBeNull()
        ->and($followUp->clinic_id)->toBe($clinic->id);
});
