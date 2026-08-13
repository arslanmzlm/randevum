<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AvailabilityReason;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\User;
use App\Modules\Core\Contracts\AppointmentCancellationContract;
use App\Modules\Core\Contracts\AppointmentLifecycleContract;
use App\Modules\Core\Contracts\DoctorLockContract;
use App\Modules\Core\Services\StatusLogService;
use App\Modules\Medical\Contracts\PatientRegistrarContract;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Scheduling\Repositories\AppointmentRepository;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService implements AppointmentCancellationContract, AppointmentLifecycleContract
{
    /**
     * Statuses eligible for bulk cancellation.
     * Arrived is intentionally excluded — a checked-in patient must be handled individually.
     *
     * @var list<AppointmentStatus>
     */
    private const BULK_CANCELLABLE_STATUSES = [
        AppointmentStatus::Confirmed,
        AppointmentStatus::Rescheduled,
    ];

    public function __construct(
        private AppointmentRepository $repository,
        private AvailabilityService $availabilityService,
        private StatusLogService $statusLogService,
        private PatientRegistrarContract $patientRegistrar,
        private ScheduleExceptionService $scheduleExceptionService,
        private ClinicContext $clinicContext,
        private AppointmentStatusSmsService $statusSms,
        private DoctorLockContract $doctorLock,
    ) {}

    /**
     * Paginated appointment list for the active clinic, scoped to the user's visibility.
     *
     * Users with `appointments.viewAll` see every doctor's appointments (further narrowable
     * by the doctor filter in the request). Users without it are hard-scoped to their own
     * doctor profile; a user with no profile receives an empty paginator.
     *
     * @return LengthAwarePaginator<Appointment>
     */
    public function listForActiveClinic(User $user): LengthAwarePaginator
    {
        $clinic = $this->clinicContext->clinicOrFail();

        if ($user->can('appointments.viewAll')) {
            $doctorIds = null;
        } else {
            $ownId = $user->doctor?->id;

            if ($ownId === null) {
                return new LengthAwarePaginator([], 0, 20);
            }

            $doctorIds = [$ownId];
        }

        return $this->repository->paginateForActiveClinic($doctorIds, $clinic->timezone);
    }

    /**
     * Create a Confirmed appointment, enforcing the 3-layer availability check,
     * and record the null → confirmed transition in status_logs.
     *
     * @param  array<string, mixed>  $data  Validated form data (starts_at in clinic-local ISO)
     *
     * @throws ValidationException When the slot fails any availability layer
     * @throws TrashedPhoneConflictException When an inline new patient reuses a soft-deleted phone
     * @throws \Throwable
     */
    public function create(array $data, User $actor): Appointment
    {
        $clinic = $this->clinicContext->clinicOrFail();

        $duration = $this->availabilityService->resolveDuration(
            ! empty($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
            ! empty($data['service_id']) ? (int) $data['service_id'] : null,
            ! empty($data['appointment_type_id']) ? (int) $data['appointment_type_id'] : null,
            $clinic,
        );
        $isWalkIn = (bool) ($data['is_walk_in'] ?? false);
        $doctorId = (int) $data['doctor_id'];

        // Parse starts_at from clinic-local string to UTC Carbon.
        $startsAt = Carbon::parse($data['starts_at'], $clinic->timezone)->utc();
        $endsAt = $startsAt->copy()->addMinutes($duration);

        $appointment = DB::transaction(function () use ($clinic, $data, $actor, $startsAt, $endsAt, $isWalkIn, $doctorId): Appointment {
            // Serialize bookings per doctor: without the lock two concurrent requests both pass
            // assertAvailable() and both insert, and no DB constraint catches the overlap.
            $this->doctorLock->lockForUpdate($doctorId);
            $this->assertAvailable($clinic, $doctorId, $startsAt, $endsAt, $isWalkIn);

            // New-patient booking registers the patient in the same transaction, so a failed
            // appointment never leaves an orphan patient behind.
            $patientId = $this->resolvePatientId($data);

            $appointment = $this->repository->create([
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'appointment_type_id' => ! empty($data['appointment_type_id']) ? (int) $data['appointment_type_id'] : null,
                'service_id' => ! empty($data['service_id']) ? (int) $data['service_id'] : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => AppointmentStatus::Confirmed->value,
                'is_walk_in' => $isWalkIn,
                'created_by' => $actor->id,
            ]);

            $this->statusLogService->record(
                $appointment,
                null,
                AppointmentStatus::Confirmed->value,
                $actor,
            );

            return $appointment;
        });

        // Post-commit: SMS dispatch is a non-fatal side-effect of the booking transition.
        $this->statusSms->sendCreated($appointment);

        return $appointment;
    }

    /**
     * Existing-patient booking returns the picked id; new-patient booking registers a minimal
     * patient through the Medical seam.
     *
     * A reused soft-deleted phone propagates the registrar's exception untouched — it carries the
     * trashed patient, which the controller needs to offer a restore instead of a dead-end error.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws TrashedPhoneConflictException When the new patient's phone belongs to a soft-deleted patient
     */
    private function resolvePatientId(array $data): int
    {
        if (($data['patient_mode'] ?? 'existing') !== 'new') {
            return (int) $data['patient_id'];
        }

        return $this->patientRegistrar->create($data['new_patient'])->id;
    }

    /**
     * Reschedule a future Confirmed or Rescheduled appointment to a new slot.
     * Re-runs the 3-layer availability check excluding the appointment's own current slot.
     * Transitions to Rescheduled (+ status_logs) only when the start time actually moves
     * and the current status is not already Rescheduled. Doctor/service/type/duration-only
     * edits keep the current status and log nothing.
     *
     * @param  array<string, mixed>  $data  Validated form data (starts_at in clinic-local ISO)
     *
     * @throws ValidationException When the appointment is not reschedulable or slot is unavailable
     * @throws \Throwable
     */
    public function reschedule(Appointment $appointment, array $data, User $actor): Appointment
    {
        $allowedStatuses = [AppointmentStatus::Confirmed, AppointmentStatus::Rescheduled];

        if (! in_array($appointment->status, $allowedStatuses, true) || ! $appointment->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'starts_at' => [__('appointment.errors.not_reschedulable')],
            ]);
        }

        $clinic = $this->clinicContext->clinicOrFail();

        $duration = $this->availabilityService->resolveDuration(
            ! empty($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
            ! empty($data['service_id']) ? (int) $data['service_id'] : null,
            ! empty($data['appointment_type_id']) ? (int) $data['appointment_type_id'] : null,
            $clinic,
        );

        $startsAt = Carbon::parse($data['starts_at'], $clinic->timezone)->utc();
        $endsAt = $startsAt->copy()->addMinutes($duration);

        $transitioned = false;

        $appointment = DB::transaction(function () use ($clinic, $appointment, $data, $actor, $startsAt, $endsAt, &$transitioned): Appointment {
            $this->doctorLock->lockForUpdate((int) $data['doctor_id']);
            $this->assertAvailable($clinic, (int) $data['doctor_id'], $startsAt, $endsAt, $appointment->is_walk_in, $appointment->id);

            $previousStartsAt = $appointment->starts_at->copy();
            $previousStatus = $appointment->status;

            $startTimeChanged = ! $startsAt->equalTo($previousStartsAt);
            $shouldTransition = $startTimeChanged && $previousStatus !== AppointmentStatus::Rescheduled;

            $updateData = [
                'doctor_id' => (int) $data['doctor_id'],
                'service_id' => ! empty($data['service_id']) ? (int) $data['service_id'] : null,
                'appointment_type_id' => ! empty($data['appointment_type_id']) ? (int) $data['appointment_type_id'] : null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ];

            if ($shouldTransition) {
                $updateData['status'] = AppointmentStatus::Rescheduled->value;
            }

            $appointment = $this->repository->update($appointment, $updateData);

            if ($shouldTransition) {
                $this->statusLogService->record(
                    $appointment,
                    $previousStatus->value,
                    AppointmentStatus::Rescheduled->value,
                    $actor,
                );

                $transitioned = true;
            }

            return $appointment;
        });

        // Post-commit: only dispatch when a real start-time move caused the Rescheduled transition.
        if ($transitioned) {
            $this->statusSms->sendRescheduled($appointment);
        }

        return $appointment;
    }

    /**
     * Cancel a Confirmed, Rescheduled, or Arrived appointment.
     * Records the transition with an optional reason to status_logs.
     *
     * @throws ValidationException When the appointment is not in a cancellable status
     * @throws \Throwable
     */
    public function cancel(Appointment $appointment, ?string $reason, User $actor): void
    {
        $allowedStatuses = [AppointmentStatus::Confirmed, AppointmentStatus::Rescheduled, AppointmentStatus::Arrived];

        if (! in_array($appointment->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [__('appointment.errors.not_cancellable')],
            ]);
        }

        DB::transaction(function () use ($appointment, $reason, $actor): void {
            $this->transitionToCancelled($appointment, $reason, $actor);
        });

        // Post-commit: single-appointment cancel path only.
        // Batch paths (bulkCancel, cancelFutureForDoctor) dispatch their own SMS after commit.
        $this->statusSms->sendCancelled($appointment);
    }

    /**
     * Preview the appointments that would be cancelled by the given bulk-cancel criteria.
     * Read-only — does not mutate any state.
     *
     * @param  array<string, mixed>  $data  Validated data with start_date, end_date, optional doctor_id
     * @return Collection<int, Appointment>
     */
    public function previewBulkCancel(array $data, User $actor): Collection
    {
        $criteria = $this->bulkCancelCriteria($data, $actor);

        return $this->repository->cancellableStartingBetween(
            $criteria['doctorIds'],
            $criteria['fromUtc'],
            $criteria['toUtc'],
            self::BULK_CANCELLABLE_STATUSES,
        );
    }

    /**
     * Cancel all Confirmed/Rescheduled appointments whose starts_at falls in the
     * selected date range and doctor scope, all inside one DB transaction.
     * Returns the number of appointments cancelled (0 when nothing matched).
     *
     * When block_new_bookings is truthy, also creates all-day schedule_exception(s)
     * for the same range/scope so new bookings in the window are rejected.
     *
     * @param  array<string, mixed>  $data  Validated data
     *
     * @throws \Throwable
     */
    public function bulkCancel(array $data, User $actor): int
    {
        $criteria = $this->bulkCancelCriteria($data, $actor);

        $appointments = $this->repository->cancellableStartingBetween(
            $criteria['doctorIds'],
            $criteria['fromUtc'],
            $criteria['toUtc'],
            self::BULK_CANCELLABLE_STATUSES,
        );

        if ($appointments->isEmpty()) {
            return 0;
        }

        $reason = $data['reason'] ?? null;

        DB::transaction(function () use ($appointments, $reason, $actor, $data, $criteria): void {
            foreach ($appointments as $appointment) {
                $this->transitionToCancelled($appointment, $reason, $actor);
            }

            if (! empty($data['block_new_bookings'])) {
                $this->applyNewBookingBlock($data, $criteria, $actor);
            }
        });

        // Post-commit: dispatch one AppointmentCancelled SMS per cancelled appointment.
        $this->statusSms->sendCancelledMany($appointments);

        return $appointments->count();
    }

    /**
     * Cancel all future Confirmed/Rescheduled appointments for the given doctor.
     * Must be called from inside an outer DB::transaction — contains no inner transaction.
     * Returns the number of appointments cancelled (0 when none).
     */
    public function cancelFutureForDoctor(int $doctorProfileId, ?string $reason, User $actor): int
    {
        $appointments = $this->repository->cancellableFutureForDoctor($doctorProfileId, self::BULK_CANCELLABLE_STATUSES);

        if ($appointments->isEmpty()) {
            return 0;
        }

        foreach ($appointments as $appointment) {
            $this->transitionToCancelled($appointment, $reason, $actor);
        }

        // Dispatch after the outer transaction commits so the job never references an uncommitted row.
        $cancelled = $appointments->all();
        DB::afterCommit(function () use ($cancelled): void {
            $this->statusSms->sendCancelledMany($cancelled);
        });

        return $appointments->count();
    }

    /**
     * Count upcoming cancellable appointments for the doctor. Read-only.
     */
    public function countCancellableFutureForDoctor(int $doctorProfileId): int
    {
        return $this->repository->countCancellableFutureForDoctor($doctorProfileId, self::BULK_CANCELLABLE_STATUSES);
    }

    /**
     * Hard-delete a mis-created appointment. Only allowed when the status is in the
     * configured allowed list and starts_at is still in the future.
     * Any other situation must use cancel instead.
     *
     * @throws ValidationException When delete is not allowed for this appointment
     */
    public function delete(Appointment $appointment, User $actor): void
    {
        $allowedStatuses = config('platform.appointment.hard_delete_allowed_statuses', []);

        if (! in_array($appointment->status->value, $allowedStatuses, true) || ! $appointment->starts_at->isFuture()) {
            throw ValidationException::withMessages([
                'appointment' => [__('appointment.errors.delete_not_allowed')],
            ]);
        }

        $appointment->forceDelete();
    }

    /**
     * Perform the status update + status_log write for a single appointment cancellation.
     * Must be called from inside a DB::transaction — contains no inner transaction.
     */
    private function transitionToCancelled(Appointment $appointment, ?string $reason, User $actor): void
    {
        $previousStatus = $appointment->status;

        $this->repository->update($appointment, ['status' => AppointmentStatus::Cancelled->value]);

        $this->statusLogService->record(
            $appointment,
            $previousStatus->value,
            AppointmentStatus::Cancelled->value,
            $actor,
            $reason,
        );
    }

    /**
     * Resolve the UTC appointment window and doctor-id list for bulk operations.
     *
     * doctorIds = null  → all clinic doctors (viewAll + no doctor_id filter)
     * doctorIds = [id]  → single doctor (explicit filter, or user's own profile)
     * doctorIds = []    → user has no doctor profile → empty result set
     *
     * @param  array<string, mixed>  $data
     * @return array{doctorIds: list<int>|null, fromUtc: Carbon, toUtc: Carbon}
     */
    private function bulkCancelCriteria(array $data, User $user): array
    {
        $clinic = $this->clinicContext->clinicOrFail();
        $tz = $clinic->timezone;

        $fromUtc = Carbon::createFromFormat('Y-m-d', $data['start_date'], $tz)->startOfDay()->utc();
        // Exclusive end: the day after end_date at 00:00 clinic-local time.
        $toUtc = Carbon::createFromFormat('Y-m-d', $data['end_date'], $tz)->addDay()->startOfDay()->utc();

        if ($user->can('appointments.viewAll')) {
            $doctorIds = isset($data['doctor_id']) ? [(int) $data['doctor_id']] : null;
        } else {
            $ownId = $user->doctor?->id;
            $doctorIds = $ownId !== null ? [$ownId] : [];
        }

        return ['doctorIds' => $doctorIds, 'fromUtc' => $fromUtc, 'toUtc' => $toUtc];
    }

    /**
     * Create all-day schedule_exception(s) to block new bookings over the cancelled range.
     * Reuses 1.15's ScheduleExceptionService::store() verbatim — no new exception logic.
     *
     * scope='clinic' when the bulk covered all doctors (doctorIds = null);
     * scope='doctor' when narrowed to a single doctor.
     *
     * @param  array<string, mixed>  $data
     * @param  array{doctorIds: list<int>|null, fromUtc: Carbon, toUtc: Carbon}  $criteria
     */
    private function applyNewBookingBlock(array $data, array $criteria, User $actor): void
    {
        $doctorIds = $criteria['doctorIds'];

        if ($doctorIds === null) {
            $scope = 'clinic';
            $blockDoctorId = null;
        } elseif (count($doctorIds) === 1) {
            $scope = 'doctor';
            $blockDoctorId = $doctorIds[0];
        } else {
            // Empty array (user with no profile): nothing to block.
            return;
        }

        $exceptionData = [
            'scope' => $scope,
            'is_all_day' => true,
            'starts_at' => $data['start_date'],
            'ends_at' => $data['end_date'],
            'reason' => $data['reason'] ?? null,
        ];

        if ($blockDoctorId !== null) {
            $exceptionData['doctor_id'] = $blockDoctorId;
        }

        $this->scheduleExceptionService->store($exceptionData, $actor);
    }

    /**
     * Standalone check-in: mark a Confirmed/Rescheduled appointment Arrived without
     * starting treatment. Thin wrapper around markArrived() — same rules, own transaction.
     *
     * @throws ValidationException When the appointment is not in an arrivable status
     * @throws \Throwable
     */
    public function checkIn(Appointment $appointment, User $actor): void
    {
        DB::transaction(function () use ($appointment, $actor): void {
            $this->markArrived($appointment, $actor);
        });
    }

    /**
     * Manually mark a Confirmed/Rescheduled appointment as NoShow.
     * Logged with the actor id, distinguishing it from the auto sweep (by_user_id = null).
     *
     * @throws ValidationException When the appointment is not in a no-showable status
     * @throws \Throwable
     */
    public function markNoShow(Appointment $appointment, User $actor, ?string $reason = null): void
    {
        $allowedStatuses = [AppointmentStatus::Confirmed, AppointmentStatus::Rescheduled];

        if (! in_array($appointment->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [__('appointment.errors.not_no_showable')],
            ]);
        }

        DB::transaction(function () use ($appointment, $reason, $actor): void {
            $previous = $appointment->status;

            $this->repository->update($appointment, ['status' => AppointmentStatus::NoShow->value]);

            $this->statusLogService->record(
                $appointment,
                $previous->value,
                AppointmentStatus::NoShow->value,
                $actor,
                $reason,
            );
        });
    }

    /**
     * Sweep every clinic with the auto-no-show toggle on and transition their past,
     * untouched Confirmed/Rescheduled non-walk-in appointments to NoShow. Logged with
     * by_user_id = null, reason = 'auto' so the manual/auto split is readable off
     * status_logs without a second status. One DB::transaction per clinic — a failure
     * in one clinic's batch never rolls back another's. Returns the total swept.
     */
    public function autoMarkNoShows(CarbonInterface $now): int
    {
        $total = 0;

        foreach (Clinic::where('auto_no_show_enabled', true)->get() as $clinic) {
            $cutoff = $now->copy()->subHours($clinic->auto_no_show_grace_hours);

            $appointments = $this->repository->noShowableForClinic($clinic->id, $cutoff);

            if ($appointments->isEmpty()) {
                continue;
            }

            DB::transaction(function () use ($appointments): void {
                foreach ($appointments as $appointment) {
                    $previous = $appointment->status;

                    $this->repository->update($appointment, ['status' => AppointmentStatus::NoShow->value]);

                    $this->statusLogService->record(
                        $appointment,
                        $previous->value,
                        AppointmentStatus::NoShow->value,
                        null,
                        'auto',
                    );
                }
            });

            $total += $appointments->count();
        }

        return $total;
    }

    /**
     * Transition a Confirmed or Rescheduled appointment to Arrived and log it.
     * No-op if already Arrived. Must be called inside a DB::transaction.
     *
     * Rescheduled → Arrived is deliberate: a patient who rescheduled and then shows up
     * checks in directly without needing a separate Confirmed step.
     */
    public function markArrived(Appointment $appointment, User $actor): void
    {
        if ($appointment->status === AppointmentStatus::Arrived) {
            return;
        }

        $allowedStatuses = [AppointmentStatus::Confirmed, AppointmentStatus::Rescheduled];

        if (! in_array($appointment->status, $allowedStatuses, true)) {
            throw ValidationException::withMessages([
                'status' => [__('appointment.errors.not_arrivable')],
            ]);
        }

        $previous = $appointment->status;

        $this->repository->update($appointment, ['status' => AppointmentStatus::Arrived->value]);

        $this->statusLogService->record(
            $appointment,
            $previous->value,
            AppointmentStatus::Arrived->value,
            $actor,
        );
    }

    /**
     * Transition an Arrived appointment to Completed, denormalize case_id, and log.
     * Must be called inside a DB::transaction.
     */
    public function markCompleted(Appointment $appointment, User $actor, ?int $caseId = null): void
    {
        if ($appointment->status !== AppointmentStatus::Arrived) {
            throw ValidationException::withMessages([
                'status' => [__('appointment.errors.not_completable')],
            ]);
        }

        $updateData = ['status' => AppointmentStatus::Completed->value];

        if ($caseId !== null) {
            $updateData['case_id'] = $caseId;
        }

        $this->repository->update($appointment, $updateData);

        $this->statusLogService->record(
            $appointment,
            AppointmentStatus::Arrived->value,
            AppointmentStatus::Completed->value,
            $actor,
        );
    }

    /**
     * Book follow-up appointments from an explicit occurrence list, skipping any that fail
     * the 3-layer availability check (no walk-in bypass). Skipped occurrences are collected
     * as formatted date strings for the toast. Cap of 12 enforced as defence-in-depth.
     *
     * @param  array{
     *     doctor_id: int,
     *     patient_id: int,
     *     case_id: int|null,
     *     service_id: int|null,
     *     occurrences: list<array{starts_at: string, duration_minutes?: int|null, appointment_type_id?: int|null}>,
     * }  $criteria
     * @return array{created: list<Appointment>, skipped: list<string>}
     */
    public function scheduleFollowUps(array $criteria, User $actor): array
    {
        $clinic = $this->clinicContext->clinicOrFail();

        $doctorId = (int) $criteria['doctor_id'];
        $patientId = (int) $criteria['patient_id'];
        $caseId = isset($criteria['case_id']) ? (int) $criteria['case_id'] : null;
        $serviceId = isset($criteria['service_id']) ? (int) $criteria['service_id'] : null;

        // Cap at 12 as defence-in-depth; FormRequest already enforces this.
        $occurrences = array_slice($criteria['occurrences'], 0, 12);

        $parsed = array_map(
            fn (array $occurrence): array => [
                'starts_at' => Carbon::parse($occurrence['starts_at'], $clinic->timezone),
                'duration_minutes' => isset($occurrence['duration_minutes'])
                    ? (int) $occurrence['duration_minutes']
                    : null,
                'appointment_type_id' => isset($occurrence['appointment_type_id'])
                    ? (int) $occurrence['appointment_type_id']
                    : null,
            ],
            $occurrences,
        );

        // Sort ascending so earlier rows win conflict precedence deterministically.
        usort($parsed, fn (array $a, array $b): int => $a['starts_at']->timestamp <=> $b['starts_at']->timestamp);

        $created = [];
        $skipped = [];

        foreach ($parsed as $occurrence) {
            $localDt = $occurrence['starts_at'];
            // Per-occurrence: a package may mix types/durations — explicit duration wins,
            // then service, then the row's type default, then the clinic default.
            $duration = $this->availabilityService->resolveDuration($occurrence['duration_minutes'], $serviceId, $occurrence['appointment_type_id'], $clinic);

            $startsAt = $localDt->copy()->utc();
            $endsAt = $startsAt->copy()->addMinutes($duration);

            $reason = $this->availabilityService->unavailableReason($doctorId, $startsAt, $endsAt, false, $clinic);

            if ($reason !== null) {
                $skipped[] = $localDt->format('d.m.Y H:i');

                continue;
            }

            $appointment = $this->repository->create([
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'case_id' => $caseId,
                'service_id' => $serviceId,
                'appointment_type_id' => $occurrence['appointment_type_id'],
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => AppointmentStatus::Confirmed->value,
                'is_walk_in' => false,
                'created_by' => $actor->id,
            ]);

            $this->statusLogService->record(
                $appointment,
                null,
                AppointmentStatus::Confirmed->value,
                $actor,
            );

            $created[] = $appointment;
        }

        // Dispatch after the enclosing transaction commits (called from TreatmentService::complete).
        if (! empty($created)) {
            DB::afterCommit(function () use ($created): void {
                $this->statusSms->sendCreatedMany($created);
            });
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Dry-run the bulk booking's availability check and return the clinic-local slot strings
     * (same 'd.m.Y H:i' format as the post-book skip report) that WOULD be skipped, so the UI
     * can warn before submitting. Read-only — reuses the exact 3-layer check scheduleFollowUps
     * applies per occurrence (no walk-in bypass). Advisory: conflicts arising within the same
     * batch (a later slot overlapping an earlier just-booked one) surface only at booking time,
     * where the server remains the real gate.
     *
     * @param  array{doctor_id: int, service_id?: int|null, occurrences: list<array{starts_at: string, duration_minutes?: int|null, appointment_type_id?: int|null}>}  $data
     * @return list<string>
     */
    public function precheckBulkConflicts(array $data): array
    {
        $clinic = $this->clinicContext->clinicOrFail();

        $doctorId = (int) $data['doctor_id'];
        $serviceId = ! empty($data['service_id']) ? (int) $data['service_id'] : null;

        $conflicts = [];

        foreach (array_slice($data['occurrences'], 0, 12) as $occurrence) {
            $localDt = Carbon::parse($occurrence['starts_at'], $clinic->timezone);
            $duration = $this->availabilityService->resolveDuration(
                isset($occurrence['duration_minutes']) ? (int) $occurrence['duration_minutes'] : null,
                $serviceId,
                isset($occurrence['appointment_type_id']) ? (int) $occurrence['appointment_type_id'] : null,
                $clinic,
            );

            $startsAt = $localDt->copy()->utc();
            $endsAt = $startsAt->copy()->addMinutes($duration);

            if ($this->availabilityService->unavailableReason($doctorId, $startsAt, $endsAt, false, $clinic) !== null) {
                $conflicts[] = $localDt->format('d.m.Y H:i');
            }
        }

        return $conflicts;
    }

    /**
     * Standalone "N appointments for one patient" booking (reception bulk-create), reusing the
     * scheduleFollowUps engine with case_id = null. Wraps patient resolution + booking in one
     * transaction — scheduleFollowUps assumes an enclosing transaction (its SMS dispatch is
     * DB::afterCommit) and normally runs inside TreatmentService::complete's own transaction.
     *
     * @param  array<string, mixed>  $data  Validated data (patient block + doctor_id + service_id + occurrences)
     * @return array{created: list<Appointment>, skipped: list<string>}
     *
     * @throws TrashedPhoneConflictException When an inline new patient reuses a soft-deleted phone
     * @throws \Throwable
     */
    public function bulkBook(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor): array {
            $patientId = $this->resolvePatientId($data);

            return $this->scheduleFollowUps([
                'doctor_id' => (int) $data['doctor_id'],
                'patient_id' => $patientId,
                'case_id' => null,
                'service_id' => ! empty($data['service_id']) ? (int) $data['service_id'] : null,
                'occurrences' => $data['occurrences'],
            ], $actor);
        });
    }

    /**
     * Run each availability layer in order and throw a typed ValidationException
     * per layer so the frontend can display a meaningful inline error on starts_at.
     */
    private function assertAvailable(Clinic $clinic, int $doctorId, mixed $startsAt, mixed $endsAt, bool $isWalkIn, ?int $excludeAppointmentId = null): void
    {
        $reason = $this->availabilityService->unavailableReason($doctorId, $startsAt, $endsAt, $isWalkIn, $clinic, $excludeAppointmentId);

        if ($reason === null) {
            return;
        }

        throw ValidationException::withMessages([
            'starts_at' => [match ($reason) {
                AvailabilityReason::OutsideHours => __('appointment.errors.outside_hours'),
                AvailabilityReason::ScheduleException => __('appointment.errors.exception'),
                AvailabilityReason::Conflict => __('appointment.errors.conflict'),
            }],
        ]);
    }
}
