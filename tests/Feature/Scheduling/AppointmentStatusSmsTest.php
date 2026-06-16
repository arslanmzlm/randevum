<?php

use App\Enums\AppointmentStatus;
use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Models\User;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Scheduling\Services\AppointmentService;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

function statusSmsAssignRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Create a future appointment for the given clinic/doctor/patient.
 *
 * @param  array<string, mixed>  $overrides
 */
function statusSmsAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
{
    $mondayUtc = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0)->utc();

    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $mondayUtc,
        'ends_at' => $mondayUtc->copy()->addMinutes(30),
    ], $overrides));
}

/**
 * Valid POST /appointments payload.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function statusSmsCreatePayload(int $patientId, int $doctorId, array $overrides = []): array
{
    $slot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 10:00:00';

    return array_merge([
        'patient_mode' => 'existing',
        'patient_id' => $patientId,
        'doctor_id' => $doctorId,
        'service_id' => null,
        'starts_at' => $slot,
        'duration_minutes' => 30,
        'is_walk_in' => false,
    ], $overrides);
}

it('booking a Confirmed appointment dispatches one AppointmentCreated SMS through the gate', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);

    $this->actingAs($owner)
        ->post(route('appointments.store'), statusSmsCreatePayload($patient->id, $doctor->id))
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class, 1);
    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($clinic, $patient): bool {
        return $job->message->type === SmsType::AppointmentCreated
            && $job->message->clinicId === $clinic->id
            && $job->message->patientId === $patient->id
            && $job->message->loggableType === 'appointment';
    });
});

it('cancelling an appointment dispatches one AppointmentCancelled SMS', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);
    $appointment = statusSmsAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class, 1);
    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($clinic, $patient, $appointment): bool {
        return $job->message->type === SmsType::AppointmentCancelled
            && $job->message->clinicId === $clinic->id
            && $job->message->patientId === $patient->id
            && $job->message->loggableId === $appointment->id;
    });
});

it('rescheduling to a new start time dispatches one AppointmentRescheduled SMS', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);
    $appointment = statusSmsAppointment($clinic, $doctor, $patient);

    // Move to Tuesday 10:00 (different day → definitely a different start time).
    $newSlot = Carbon::now('Europe/Istanbul')->next(Carbon::TUESDAY)->format('Y-m-d').' 10:00:00';

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), [
            'doctor_id' => $doctor->id,
            'starts_at' => $newSlot,
            'duration_minutes' => 30,
        ])
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class, 1);
    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($clinic, $patient, $appointment): bool {
        return $job->message->type === SmsType::AppointmentRescheduled
            && $job->message->clinicId === $clinic->id
            && $job->message->patientId === $patient->id
            && $job->message->loggableId === $appointment->id;
    });
});

it('a doctor-only or duration-only edit without a start-time move dispatches nothing', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);

    $mondayUtc = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0)->utc();
    $appointment = Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $mondayUtc,
        'ends_at' => $mondayUtc->copy()->addMinutes(30),
    ]);

    // Same start time as current — no time move, no transition.
    $sameSlot = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 10:00:00';

    $this->actingAs($owner)
        ->put(route('appointments.update', $appointment), [
            'doctor_id' => $doctor->id,
            'starts_at' => $sameSlot,
            'duration_minutes' => 45, // only duration changed
        ])
        ->assertRedirect();

    Queue::assertNothingPushed();
});

it('a disabled appointment_created toggle produces a Skipped log row and no queued job, but booking still succeeds', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);

    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCreated)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), statusSmsCreatePayload($patient->id, $doctor->id))
        ->assertRedirect();

    // Appointment was persisted.
    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists())->toBeTrue();

    // Gate wrote a Skipped log but did not push the job.
    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('type', SmsType::AppointmentCreated)
        ->sole();

    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->error)->toBe('disabled by clinic')
        ->and($log->clinic_id)->toBe($clinic->id);
});

it('a disabled appointment_cancelled toggle produces a Skipped log, no job, cancel still succeeds', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);
    $appointment = statusSmsAppointment($clinic, $doctor, $patient);

    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCancelled)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect();

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Cancelled);

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('type', SmsType::AppointmentCancelled)
        ->sole();

    expect($log->status)->toBe(SmsStatus::Skipped);
});

it('patient with null phone produces a Skipped log with error=no phone and does not crash booking', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => null]);

    $this->actingAs($owner)
        ->post(route('appointments.store'), statusSmsCreatePayload($patient->id, $doctor->id))
        ->assertRedirect();

    // Appointment persisted.
    expect(Appointment::withoutGlobalScopes()->where('clinic_id', $clinic->id)->exists())->toBeTrue();

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('type', SmsType::AppointmentCreated)
        ->sole();

    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->error)->toBe('no phone');
});

it('patient with null phone on cancel produces Skipped log and cancel still succeeds', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => null]);
    $appointment = statusSmsAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect();

    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Cancelled);

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('type', SmsType::AppointmentCancelled)
        ->sole();

    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->error)->toBe('no phone');
});

it('a dispatcher that throws does not roll back the appointment creation (post-commit isolation)', function (): void {
    // Bind a dispatcher that always throws — proves the try/catch in the SMS service absorbs it.
    app()->bind(SmsDispatcherContract::class, function (): SmsDispatcherContract {
        return new class implements SmsDispatcherContract
        {
            public function dispatch(SmsMessage $message): void
            {
                throw new RuntimeException('Simulated dispatcher failure');
            }

            public function wasSent(string $loggableType, int $loggableId, SmsType $type): bool
            {
                return false;
            }
        };
    });

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);

    $this->actingAs($owner)
        ->post(route('appointments.store'), statusSmsCreatePayload($patient->id, $doctor->id))
        ->assertRedirect();

    // The appointment must have been persisted despite the dispatcher throwing.
    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('patient_id', $patient->id)
        ->where('status', AppointmentStatus::Confirmed)
        ->exists()
    )->toBeTrue();
});

it('a dispatcher that throws does not roll back the cancellation (post-commit isolation)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);
    $appointment = statusSmsAppointment($clinic, $doctor, $patient);

    app()->bind(SmsDispatcherContract::class, function (): SmsDispatcherContract {
        return new class implements SmsDispatcherContract
        {
            public function dispatch(SmsMessage $message): void
            {
                throw new RuntimeException('Simulated dispatcher failure');
            }

            public function wasSent(string $loggableType, int $loggableId, SmsType $type): bool
            {
                return false;
            }
        };
    });

    $this->actingAs($owner)
        ->patch(route('appointments.cancel', $appointment), [])
        ->assertRedirect();

    // Cancel must have succeeded — status is Cancelled.
    $appointment->refresh();
    expect($appointment->status)->toBe(AppointmentStatus::Cancelled);
});

it('bulkCancel dispatches one AppointmentCancelled per cancelled appointment', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $targetDate = Carbon::tomorrow('Europe/Istanbul')->format('Y-m-d');
    $startsAt = Carbon::createFromFormat('Y-m-d', $targetDate, $clinic->timezone)->setTime(10, 0, 0)->utc();

    $patientA = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321111111']);
    $patientB = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905322222222']);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patientA->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patientB->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $startsAt->copy()->addMinutes(30),
        'ends_at' => $startsAt->copy()->addMinutes(60),
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel'), [
            'start_date' => $targetDate,
            'end_date' => $targetDate,
            'doctor_id' => null,
            'reason' => null,
            'block_new_bookings' => false,
        ])
        ->assertRedirect();

    // Two appointments bulk-cancelled → two AppointmentCancelled jobs dispatched.
    Queue::assertPushed(SendSmsJob::class, 2);
    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job): bool {
        return $job->message->type === SmsType::AppointmentCancelled;
    });
});

it('bulk-cancel with a disabled toggle still cancels but logs Skipped (not sends)', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCancelled)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    $targetDate = Carbon::tomorrow('Europe/Istanbul')->format('Y-m-d');
    $startsAt = Carbon::createFromFormat('Y-m-d', $targetDate, $clinic->timezone)->setTime(10, 0, 0)->utc();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel'), [
            'start_date' => $targetDate,
            'end_date' => $targetDate,
            'doctor_id' => null,
            'reason' => null,
            'block_new_bookings' => false,
        ])
        ->assertRedirect();

    // All appointments were cancelled despite the disabled toggle.
    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('status', AppointmentStatus::Cancelled)
        ->count()
    )->toBe(1);

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()->where('type', SmsType::AppointmentCancelled)->sole();
    expect($log->status)->toBe(SmsStatus::Skipped);
});

it('a throwing dispatcher in bulkCancel does not roll back the bulk cancellation', function (): void {
    app()->bind(SmsDispatcherContract::class, function (): SmsDispatcherContract {
        return new class implements SmsDispatcherContract
        {
            public function dispatch(SmsMessage $message): void
            {
                throw new RuntimeException('Simulated dispatcher failure');
            }

            public function wasSent(string $loggableType, int $loggableId, SmsType $type): bool
            {
                return false;
            }
        };
    });

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $targetDate = Carbon::tomorrow('Europe/Istanbul')->format('Y-m-d');
    $startsAt = Carbon::createFromFormat('Y-m-d', $targetDate, $clinic->timezone)->setTime(10, 0, 0)->utc();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);

    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $this->actingAs($owner)
        ->post(route('appointments.bulk-cancel'), [
            'start_date' => $targetDate,
            'end_date' => $targetDate,
            'doctor_id' => null,
            'reason' => null,
            'block_new_bookings' => false,
        ])
        ->assertRedirect();

    // Bulk cancel succeeded — appointment is Cancelled despite the dispatcher throwing.
    expect(Appointment::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('status', AppointmentStatus::Cancelled)
        ->exists()
    )->toBeTrue();
});

it('cancelFutureForDoctor (offboarding) dispatches one AppointmentCancelled per cancelled future appointment', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321111111']);
    $patientB = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905322222222']);

    app(ClinicContext::class)->set($clinic->id);

    statusSmsAppointment($clinic, $doctor, $patientA);
    statusSmsAppointment($clinic, $doctor, $patientB, [
        'starts_at' => Carbon::now('Europe/Istanbul')->next(Carbon::TUESDAY)->setTime(10, 0, 0)->utc(),
        'ends_at' => Carbon::now('Europe/Istanbul')->next(Carbon::TUESDAY)->setTime(10, 30, 0)->utc(),
    ]);

    $service = app(AppointmentService::class);

    // cancelFutureForDoctor must run inside an outer transaction (mirrors DoctorProfileService offboarding).
    DB::transaction(function () use ($service, $doctor, $owner): void {
        $service->cancelFutureForDoctor($doctor->id, null, $owner);
    });

    // Each cancelled future appointment SMSes its OWN patient, attributed to its own clinic.
    Queue::assertPushed(SendSmsJob::class, 2);
    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($clinic, $patientA): bool {
        return $job->message->type === SmsType::AppointmentCancelled
            && $job->message->clinicId === $clinic->id
            && $job->message->patientId === $patientA->id;
    });
    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($clinic, $patientB): bool {
        return $job->message->type === SmsType::AppointmentCancelled
            && $job->message->clinicId === $clinic->id
            && $job->message->patientId === $patientB->id;
    });
});

it('scheduleFollowUps dispatches one AppointmentCreated per created follow-up appointment', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    statusSmsAssignRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);

    // Set the clinic context so AppointmentService can resolve the clinic.
    app(ClinicContext::class)->set($clinic->id);

    $service = app(AppointmentService::class);

    $monday = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->format('Y-m-d').' 10:00:00';
    $tuesday = Carbon::now('Europe/Istanbul')->next(Carbon::TUESDAY)->format('Y-m-d').' 10:00:00';

    $result = $service->scheduleFollowUps([
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'case_id' => null,
        'service_id' => null,
        'occurrences' => [
            ['starts_at' => $monday, 'duration_minutes' => 30],
            ['starts_at' => $tuesday, 'duration_minutes' => 30],
        ],
    ], $owner);

    expect(count($result['created']))->toBe(2);

    // scheduleFollowUps uses DB::afterCommit — in test mode with no wrapping
    // transaction, afterCommit fires immediately after the innermost "commit".
    Queue::assertPushed(SendSmsJob::class, 2);
    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job) use ($clinic, $patient): bool {
        return $job->message->type === SmsType::AppointmentCreated
            && $job->message->clinicId === $clinic->id
            && $job->message->patientId === $patient->id;
    });
});

it('sms_logs for clinic A booking carry clinic A id and clinic A name in body, never clinic B', function (): void {
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);

    $clinicA = Clinic::factory()->create(['name' => 'Clinic Alpha', 'timezone' => 'Europe/Istanbul', 'locale' => 'tr_TR']);
    $ownerA = User::factory()->create();
    statusSmsAssignRole($ownerA, 'owner', $clinicA->id);
    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id, 'phone' => '+905321111111']);

    $clinicB = Clinic::factory()->create(['name' => 'Clinic Beta', 'timezone' => 'Europe/Istanbul', 'locale' => 'tr_TR']);
    $ownerB = User::factory()->create();
    statusSmsAssignRole($ownerB, 'owner', $clinicB->id);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id, 'phone' => '+905322222222']);

    // Book appointment for clinic A.
    app(ClinicContext::class)->forget();
    $this->actingAs($ownerA)
        ->post(route('appointments.store'), statusSmsCreatePayload($patientA->id, $doctorA->id))
        ->assertRedirect();

    // Book appointment for clinic B.
    app(ClinicContext::class)->forget();
    $this->actingAs($ownerB)
        ->post(route('appointments.store'), statusSmsCreatePayload($patientB->id, $doctorB->id))
        ->assertRedirect();

    $logA = SmsLog::withoutGlobalScopes()
        ->where('type', SmsType::AppointmentCreated)
        ->where('patient_id', $patientA->id)
        ->sole();

    $logB = SmsLog::withoutGlobalScopes()
        ->where('type', SmsType::AppointmentCreated)
        ->where('patient_id', $patientB->id)
        ->sole();

    expect($logA->clinic_id)->toBe($clinicA->id)
        ->and($logB->clinic_id)->toBe($clinicB->id)
        ->and($logA->clinic_id)->not->toBe($logB->clinic_id);

    // The SMS body for clinic A must mention Clinic Alpha, not Clinic Beta.
    expect($logA->body)->toContain('Clinic Alpha')
        ->and($logA->body)->not->toContain('Clinic Beta');
});

it('clinic A cancel SMS is attributed to clinic A only, never clinic B', function (): void {
    config(['services.sms.provider' => 'null', 'queue.default' => 'sync']);

    $clinicA = Clinic::factory()->create(['name' => 'Clinic Alpha', 'timezone' => 'Europe/Istanbul', 'locale' => 'tr_TR']);
    $ownerA = User::factory()->create();
    statusSmsAssignRole($ownerA, 'owner', $clinicA->id);
    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id, 'phone' => '+905321111111']);

    $clinicB = Clinic::factory()->create(['name' => 'Clinic Beta', 'timezone' => 'Europe/Istanbul', 'locale' => 'tr_TR']);
    $ownerB = User::factory()->create();
    statusSmsAssignRole($ownerB, 'owner', $clinicB->id);
    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id, 'phone' => '+905322222222']);

    $appointmentA = statusSmsAppointment($clinicA, $doctorA, $patientA);
    $appointmentB = statusSmsAppointment($clinicB, $doctorB, $patientB);

    app(ClinicContext::class)->forget();
    $this->actingAs($ownerA)
        ->patch(route('appointments.cancel', $appointmentA), [])
        ->assertRedirect();

    app(ClinicContext::class)->forget();
    $this->actingAs($ownerB)
        ->patch(route('appointments.cancel', $appointmentB), [])
        ->assertRedirect();

    $logA = SmsLog::withoutGlobalScopes()
        ->where('type', SmsType::AppointmentCancelled)
        ->where('patient_id', $patientA->id)
        ->sole();

    $logB = SmsLog::withoutGlobalScopes()
        ->where('type', SmsType::AppointmentCancelled)
        ->where('patient_id', $patientB->id)
        ->sole();

    expect($logA->clinic_id)->toBe($clinicA->id)
        ->and($logB->clinic_id)->toBe($clinicB->id)
        ->and($logA->body)->toContain('Clinic Alpha')
        ->and($logA->body)->not->toContain('Clinic Beta');
});
