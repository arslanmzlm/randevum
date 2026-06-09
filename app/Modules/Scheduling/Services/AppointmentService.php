<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\AppointmentStatus;
use App\Enums\AvailabilityReason;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\User;
use App\Modules\Core\Services\StatusLogService;
use App\Modules\Medical\Contracts\PatientRegistrarContract;
use App\Modules\Medical\Exceptions\TrashedPhoneConflictException;
use App\Modules\Scheduling\Repositories\AppointmentRepository;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
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
        $clinic = Clinic::findOrFail($this->clinicContext->id());

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
     * @throws \Throwable
     */
    public function create(array $data, User $actor): Appointment
    {
        $clinic = Clinic::findOrFail($this->clinicContext->id());

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

        $this->assertAvailable($clinic, $doctorId, $startsAt, $endsAt, $isWalkIn);

        return DB::transaction(function () use ($data, $actor, $startsAt, $endsAt, $isWalkIn, $doctorId): Appointment {
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
    }

    /**
     * Existing-patient booking returns the picked id; new-patient booking registers a minimal
     * patient through the Medical seam. A reused soft-deleted phone surfaces as an inline error.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolvePatientId(array $data): int
    {
        if (($data['patient_mode'] ?? 'existing') !== 'new') {
            return (int) $data['patient_id'];
        }

        try {
            return $this->patientRegistrar->create($data['new_patient'])->id;
        } catch (TrashedPhoneConflictException) {
            throw ValidationException::withMessages([
                'new_patient.phone' => [__('appointment.errors.phone_trashed')],
            ]);
        }
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

        $clinic = Clinic::findOrFail($this->clinicContext->id());

        $duration = $this->availabilityService->resolveDuration(
            ! empty($data['duration_minutes']) ? (int) $data['duration_minutes'] : null,
            ! empty($data['service_id']) ? (int) $data['service_id'] : null,
            ! empty($data['appointment_type_id']) ? (int) $data['appointment_type_id'] : null,
            $clinic,
        );

        $startsAt = Carbon::parse($data['starts_at'], $clinic->timezone)->utc();
        $endsAt = $startsAt->copy()->addMinutes($duration);

        $this->assertAvailable($clinic, (int) $data['doctor_id'], $startsAt, $endsAt, $appointment->is_walk_in, $appointment->id);

        return DB::transaction(function () use ($appointment, $data, $actor, $startsAt, $endsAt): Appointment {
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
            }

            return $appointment;
        });
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

        return $appointments->count();
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
        $clinic = Clinic::findOrFail($this->clinicContext->id());
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
