<?php

use App\Enums\AppointmentStatus;
use App\Enums\AvailabilityReason;
use App\Models\Appointment;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\User;
use App\Modules\Scheduling\Repositories\AppointmentRepository;
use App\Modules\Scheduling\Services\AvailabilityService;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    app(ClinicContext::class)->forget();
});

// ---------------------------------------------------------------------------
// AppointmentStatus enum — values and casing
// ---------------------------------------------------------------------------

test('AppointmentStatus enum has all 7 expected cases with correct string values', function (): void {
    expect(AppointmentStatus::Pending->value)->toBe('pending')
        ->and(AppointmentStatus::Confirmed->value)->toBe('confirmed')
        ->and(AppointmentStatus::Rescheduled->value)->toBe('rescheduled')
        ->and(AppointmentStatus::Arrived->value)->toBe('arrived')
        ->and(AppointmentStatus::Completed->value)->toBe('completed')
        ->and(AppointmentStatus::Cancelled->value)->toBe('cancelled')
        ->and(AppointmentStatus::NoShow->value)->toBe('no_show');
});

test('AppointmentStatus::Cancelled is spelled with double-l', function (): void {
    // State-machine rule: spelling is always Cancelled (double-l).
    expect(AppointmentStatus::Cancelled->name)->toBe('Cancelled')
        ->and(AppointmentStatus::Cancelled->value)->toBe('cancelled');
});

// ---------------------------------------------------------------------------
// AvailabilityService::insideWorkingHours — layer 1
// ---------------------------------------------------------------------------

test('insideWorkingHours returns true for a slot within weekday opening hours', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    // 10:00-10:30 Istanbul — within 09:00-19:00, not in break
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->insideWorkingHours($clinic, $start, $end))->toBeTrue();
});

test('insideWorkingHours returns false when slot starts before opening time', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    // 08:00-08:30 Istanbul — before opening (09:00)
    $start = $monday->copy()->setTime(8, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->insideWorkingHours($clinic, $start, $end))->toBeFalse();
});

test('insideWorkingHours returns false when slot extends past closing time', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    // 19:00-19:30 Istanbul — extends past closing (19:00)
    $start = $monday->copy()->setTime(19, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->insideWorkingHours($clinic, $start, $end))->toBeFalse();
});

test('insideWorkingHours returns false when slot overlaps the break window', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    // 12:15-12:45 Istanbul — inside break (12:00-13:30)
    $start = $monday->copy()->setTime(12, 15, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->insideWorkingHours($clinic, $start, $end))->toBeFalse();
});

test('insideWorkingHours allows a slot that ends exactly at break start (back-to-back with break)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    // 11:30-12:00 Istanbul — ends exactly when break starts; strict overlap means no conflict
    $start = $monday->copy()->setTime(11, 30, 0)->utc();
    $end = $monday->copy()->setTime(12, 0, 0)->utc();

    expect($service->insideWorkingHours($clinic, $start, $end))->toBeTrue();
});

test('insideWorkingHours allows a slot that starts exactly at break end (back-to-back with break)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    // 13:30-14:00 Istanbul — starts exactly when break ends; strict overlap means no conflict
    $start = $monday->copy()->setTime(13, 30, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->insideWorkingHours($clinic, $start, $end))->toBeTrue();
});

test('insideWorkingHours returns false on a closed day (Sunday)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $service = app(AvailabilityService::class);

    $sunday = Carbon::now('Europe/Istanbul')->next(Carbon::SUNDAY);
    $start = $sunday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->insideWorkingHours($clinic, $start, $end))->toBeFalse();
});

// ---------------------------------------------------------------------------
// AvailabilityService::hasScheduleExceptionOverlap — layer 2
// ---------------------------------------------------------------------------

test('hasScheduleExceptionOverlap returns false when no exception exists', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->hasScheduleExceptionOverlap($doctor->id, $start, $end))->toBeFalse();
});

test('hasScheduleExceptionOverlap returns true when an exception overlaps the slot', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30), // 09:30 — overlaps
        'ends_at' => $start->copy()->addMinutes(15),   // 10:15 — overlaps
    ]);

    expect($service->hasScheduleExceptionOverlap($doctor->id, $start, $end))->toBeTrue();
});

test('hasScheduleExceptionOverlap returns false when exception ends exactly when slot starts (back-to-back)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30), // 09:30
        'ends_at' => $start->copy(),                   // 10:00 — ends exactly when slot starts
    ]);

    expect($service->hasScheduleExceptionOverlap($doctor->id, $start, $end))->toBeFalse();
});

// ---------------------------------------------------------------------------
// AppointmentRepository::hasConflictingAppointment — layer 3
// ---------------------------------------------------------------------------

test('hasConflictingAppointment returns false when no appointments exist', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $repo = app(AppointmentRepository::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($repo->hasConflictingAppointment($doctor->id, $start, $end))->toBeFalse();
});

test('hasConflictingAppointment returns true for an overlapping Confirmed appointment', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $repo = app(AppointmentRepository::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    expect($repo->hasConflictingAppointment($doctor->id, $start, $end))->toBeTrue();
});

test('hasConflictingAppointment returns true for an overlapping Arrived appointment', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $repo = app(AppointmentRepository::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    Appointment::factory()->withStatus(AppointmentStatus::Arrived)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
    ]);

    expect($repo->hasConflictingAppointment($doctor->id, $start, $end))->toBeTrue();
});

test('hasConflictingAppointment returns false when existing appointment ends exactly when new one starts (back-to-back)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $repo = app(AppointmentRepository::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy(), // ends exactly when new slot starts
        'status' => AppointmentStatus::Confirmed,
    ]);

    expect($repo->hasConflictingAppointment($doctor->id, $start, $end))->toBeFalse();
});

test('hasConflictingAppointment ignores Completed appointments (not a blocker)', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $repo = app(AppointmentRepository::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    Appointment::factory()->withStatus(AppointmentStatus::Completed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
    ]);

    expect($repo->hasConflictingAppointment($doctor->id, $start, $end))->toBeFalse();
});

// ---------------------------------------------------------------------------
// AvailabilityService::isAvailable — combined walk-in bypass
// ---------------------------------------------------------------------------

test('isAvailable returns true for a clean slot with no exceptions or conflicts', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->isAvailable($doctor->id, $start, $end, false, $clinic))->toBeTrue();
});

test('isAvailable returns false outside working hours even for walk-in', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    // 08:00 is before opening
    $start = $monday->copy()->setTime(8, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->isAvailable($doctor->id, $start, $end, true, $clinic))->toBeFalse();
});

test('isAvailable returns false for non-walk-in when a schedule exception overlaps', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
    ]);

    expect($service->isAvailable($doctor->id, $start, $end, false, $clinic))->toBeFalse();
});

test('isAvailable returns true for walk-in even when a schedule exception overlaps (layer 2 bypassed)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
    ]);

    expect($service->isAvailable($doctor->id, $start, $end, true, $clinic))->toBeTrue();
});

test('isAvailable returns false for non-walk-in when a Confirmed appointment overlaps', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    expect($service->isAvailable($doctor->id, $start, $end, false, $clinic))->toBeFalse();
});

test('isAvailable returns true for walk-in even when a Confirmed appointment overlaps (layer 3 bypassed)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    expect($service->isAvailable($doctor->id, $start, $end, true, $clinic))->toBeTrue();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation — AvailabilityService only sees active clinic's data
// ---------------------------------------------------------------------------

test('clinic B appointment does not count as a conflict when ClinicContext is set to clinic A', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    // Clinic B's doctor has a conflicting appointment
    Appointment::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    // With context set to clinicA, doctorA's slot has no conflict (clinicB's data invisible)
    app(ClinicContext::class)->set($clinicA->id);
    $repo = app(AppointmentRepository::class);

    expect($repo->hasConflictingAppointment($doctorA->id, $start, $end))->toBeFalse();
});

test('clinic B schedule exception does not block doctor A when ClinicContext is set to clinic A', function (): void {
    $clinicA = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $clinicB = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    // Clinic B's doctor has an overlapping schedule exception
    ScheduleException::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
    ]);

    // With context set to clinicA, doctorA has no exception overlap (clinicB's data invisible)
    app(ClinicContext::class)->set($clinicA->id);
    $service = app(AvailabilityService::class);

    expect($service->hasScheduleExceptionOverlap($doctorA->id, $start, $end))->toBeFalse();
});

// ---------------------------------------------------------------------------
// AvailabilityService::unavailableReason — typed reason enum per layer
// ---------------------------------------------------------------------------

test('unavailableReason returns OutsideHours when slot is before opening time', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(8, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->unavailableReason($doctor->id, $start, $end, false, $clinic))
        ->toBe(AvailabilityReason::OutsideHours);
});

test('unavailableReason returns ScheduleException when an exception overlaps', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
    ]);

    expect($service->unavailableReason($doctor->id, $start, $end, false, $clinic))
        ->toBe(AvailabilityReason::ScheduleException);
});

test('unavailableReason returns Conflict when a Confirmed appointment overlaps', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    expect($service->unavailableReason($doctor->id, $start, $end, false, $clinic))
        ->toBe(AvailabilityReason::Conflict);
});

test('unavailableReason returns null for a clean slot (no blockers)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->unavailableReason($doctor->id, $start, $end, false, $clinic))->toBeNull();
});

test('unavailableReason returns null for walk-in when a schedule exception overlaps (layer 2 bypassed)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
    ]);

    expect($service->unavailableReason($doctor->id, $start, $end, true, $clinic))->toBeNull();
});

test('unavailableReason returns null for walk-in when a Confirmed appointment overlaps (layer 3 bypassed)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    expect($service->unavailableReason($doctor->id, $start, $end, true, $clinic))->toBeNull();
});

test('unavailableReason returns OutsideHours for walk-in outside working hours (layer 1 always applies)', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(8, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    expect($service->unavailableReason($doctor->id, $start, $end, true, $clinic))
        ->toBe(AvailabilityReason::OutsideHours);
});

test('unavailableReason returns OutsideHours before ScheduleException (layer ordering)', function (): void {
    // Both layer 1 and layer 2 would fail — layer 1 (OutsideHours) must be returned first.
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    // 08:00 — before opening
    $start = $monday->copy()->setTime(8, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->subMinutes(30),
        'ends_at' => $start->copy()->addMinutes(60),
    ]);

    expect($service->unavailableReason($doctor->id, $start, $end, false, $clinic))
        ->toBe(AvailabilityReason::OutsideHours);
});

// ---------------------------------------------------------------------------
// AvailabilityService::resolveDuration — priority chain
// ---------------------------------------------------------------------------

test('resolveDuration uses explicit duration_minutes override when provided', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $service = app(AvailabilityService::class);

    expect($service->resolveDuration(60, null, null, $clinic))->toBe(60);
});

test('resolveDuration uses service duration_minutes when no explicit override', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $svc = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => 45]);
    $service = app(AvailabilityService::class);

    expect($service->resolveDuration(null, $svc->id, null, $clinic))->toBe(45);
});

test('resolveDuration falls back to clinic default when neither override nor service provided', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $service = app(AvailabilityService::class);

    expect($service->resolveDuration(null, null, null, $clinic))->toBe(30);
});

test('resolveDuration falls back to clinic default when service has null duration_minutes', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $svc = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => null]);
    $service = app(AvailabilityService::class);

    expect($service->resolveDuration(null, $svc->id, null, $clinic))->toBe(30);
});

test('resolveDuration explicit override beats service duration', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $svc = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => 45]);
    $service = app(AvailabilityService::class);

    expect($service->resolveDuration(90, $svc->id, null, $clinic))->toBe(90);
});

test('resolveDuration uses appointment type default when no explicit override or service duration', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $type = AppointmentType::factory()->create(['clinic_id' => $clinic->id, 'default_duration_minutes' => 40]);
    $service = app(AvailabilityService::class);

    expect($service->resolveDuration(null, null, $type->id, $clinic))->toBe(40);
});

test('resolveDuration service duration beats appointment type default', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $svc = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => 45]);
    $type = AppointmentType::factory()->create(['clinic_id' => $clinic->id, 'default_duration_minutes' => 40]);
    $service = app(AvailabilityService::class);

    expect($service->resolveDuration(null, $svc->id, $type->id, $clinic))->toBe(45);
});

test('resolveDuration falls back to clinic default when appointment type has no duration and service also null', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $svc = Service::factory()->create(['clinic_id' => $clinic->id, 'duration_minutes' => null]);
    $type = AppointmentType::factory()->create(['clinic_id' => $clinic->id, 'default_duration_minutes' => 0]);
    $service = app(AvailabilityService::class);

    // AppointmentType with 0 duration is falsy — falls through to clinic default.
    expect($service->resolveDuration(null, $svc->id, $type->id, $clinic))->toBe(30);
});

// ---------------------------------------------------------------------------
// excludeAppointmentId — reschedule self-exclusion (layer 3)
// ---------------------------------------------------------------------------

test('hasConflictingAppointment returns false when the only overlapping appointment is the excluded one', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $repo = app(AppointmentRepository::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    // The appointment at 10:00-10:30 is the one being rescheduled — it should not block itself.
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Without exclusion → conflict with itself.
    expect($repo->hasConflictingAppointment($doctor->id, $start, $end))->toBeTrue();

    // With exclusion → no conflict (the appointment ignores its own slot).
    expect($repo->hasConflictingAppointment($doctor->id, $start, $end, $appointment->id))->toBeFalse();
});

test('hasConflictingAppointment still returns true when another appointment conflicts even with the excluded id', function (): void {
    $clinic = Clinic::factory()->create();
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $repo = app(AppointmentRepository::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    // The appointment being rescheduled (excluded from conflict check).
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'status' => AppointmentStatus::Confirmed,
    ]);

    // A different appointment at 10:15 — NOT excluded — still blocks the slot.
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start->copy()->addMinutes(15),
        'ends_at' => $end->copy()->addMinutes(15),
        'status' => AppointmentStatus::Confirmed,
    ]);

    expect($repo->hasConflictingAppointment($doctor->id, $start, $end, $appointment->id))->toBeTrue();
});

test('unavailableReason with excludeAppointmentId returns null when only the excluded appointment overlaps', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    app(ClinicContext::class)->set($clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $service = app(AvailabilityService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY);
    $start = $monday->copy()->setTime(10, 0, 0)->utc();
    $end = $start->copy()->addMinutes(30);

    // Appointment at 10:00 — being rescheduled, so excluded.
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => $start,
        'ends_at' => $end,
        'status' => AppointmentStatus::Confirmed,
    ]);

    // Without exclusion → Conflict reason returned.
    expect($service->unavailableReason($doctor->id, $start, $end, false, $clinic))
        ->toBe(AvailabilityReason::Conflict);

    // With exclusion → slot is available (null reason).
    expect($service->unavailableReason($doctor->id, $start, $end, false, $clinic, $appointment->id))
        ->toBeNull();
});
