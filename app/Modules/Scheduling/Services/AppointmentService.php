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
     * Run each availability layer in order and throw a typed ValidationException
     * per layer so the frontend can display a meaningful inline error on starts_at.
     */
    private function assertAvailable(Clinic $clinic, int $doctorId, mixed $startsAt, mixed $endsAt, bool $isWalkIn): void
    {
        $reason = $this->availabilityService->unavailableReason($doctorId, $startsAt, $endsAt, $isWalkIn, $clinic);

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
