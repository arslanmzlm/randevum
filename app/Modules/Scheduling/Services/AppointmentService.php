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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AppointmentService
{
    public function __construct(
        private AppointmentRepository $repository,
        private AvailabilityService $availabilityService,
        private StatusLogService $statusLogService,
        private PatientRegistrarContract $patientRegistrar,
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
            $previousStatus = $appointment->status;

            $this->repository->update($appointment, ['status' => AppointmentStatus::Cancelled->value]);

            $this->statusLogService->record(
                $appointment,
                $previousStatus->value,
                AppointmentStatus::Cancelled->value,
                $actor,
                $reason,
            );
        });
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
