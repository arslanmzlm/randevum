<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\AvailabilityReason;
use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Modules\Scheduling\Repositories\AppointmentRepository;
use Carbon\Carbon;

/**
 * 3-layer availability check shared by the B2B manual-booking flow and the
 * future B2C online-booking flow (both live in the Scheduling module).
 */
class AvailabilityService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
    ) {}

    /**
     * Layer 1: is the slot within the clinic's working hours for that day?
     * Times are compared in the clinic's timezone.
     */
    public function insideWorkingHours(Clinic $clinic, mixed $start, mixed $end): bool
    {
        $tz = $clinic->timezone;
        $startLocal = Carbon::parse($start)->setTimezone($tz);
        $endLocal = Carbon::parse($end)->setTimezone($tz);

        $day = strtolower($startLocal->format('l')); // e.g. 'monday'

        $hours = $clinic->working_hours[$day] ?? null;

        if ($hours === null || ($hours['closed'] ?? false)) {
            return false;
        }

        $date = $startLocal->format('Y-m-d');
        $open = Carbon::parse("{$date} {$hours['open']}", $tz);
        $close = Carbon::parse("{$date} {$hours['close']}", $tz);

        if ($startLocal < $open || $endLocal > $close) {
            return false;
        }

        // Slot must not overlap the break (back-to-back with break boundary is fine).
        if (! empty($hours['break'])) {
            $breakStart = Carbon::parse("{$date} {$hours['break'][0]}", $tz);
            $breakEnd = Carbon::parse("{$date} {$hours['break'][1]}", $tz);

            if (! ($endLocal <= $breakStart || $startLocal >= $breakEnd)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Layer 2: does any schedule exception for this doctor overlap the slot?
     * Uses strict overlap so back-to-back with an exception is not blocked.
     */
    public function hasScheduleExceptionOverlap(int $doctorId, mixed $start, mixed $end): bool
    {
        return ScheduleException::forDoctor($doctorId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();
    }

    /**
     * Run each availability layer in order and return the first failing reason,
     * or null when the slot is available. Walk-ins bypass layers 2 & 3.
     * Pass $excludeAppointmentId when rescheduling so the row's own slot is not
     * treated as a conflict in layer 3.
     */
    public function unavailableReason(int $doctorId, mixed $start, mixed $end, bool $isWalkIn, Clinic $clinic, ?int $excludeAppointmentId = null): ?AvailabilityReason
    {
        if (! $this->insideWorkingHours($clinic, $start, $end)) {
            return AvailabilityReason::OutsideHours;
        }

        if ($isWalkIn) {
            return null;
        }

        if ($this->hasScheduleExceptionOverlap($doctorId, $start, $end)) {
            return AvailabilityReason::ScheduleException;
        }

        if ($this->appointmentRepository->hasConflictingAppointment($doctorId, $start, $end, $excludeAppointmentId)) {
            return AvailabilityReason::Conflict;
        }

        return null;
    }

    /**
     * Combined check: layer 1 always; layers 2 & 3 only when !$isWalkIn.
     */
    public function isAvailable(int $doctorId, mixed $start, mixed $end, bool $isWalkIn, Clinic $clinic, ?int $excludeAppointmentId = null): bool
    {
        return $this->unavailableReason($doctorId, $start, $end, $isWalkIn, $clinic, $excludeAppointmentId) === null;
    }

    /**
     * Resolve slot duration with priority:
     * explicit override → service duration_minutes → appointment type default → clinic default.
     */
    public function resolveDuration(?int $durationMinutes, ?int $serviceId, ?int $appointmentTypeId, Clinic $clinic): int
    {
        if ($durationMinutes !== null) {
            return $durationMinutes;
        }

        if ($serviceId !== null) {
            $service = Service::find($serviceId);
            if ($service?->duration_minutes) {
                return $service->duration_minutes;
            }
        }

        if ($appointmentTypeId !== null) {
            $type = AppointmentType::find($appointmentTypeId);
            if ($type?->default_duration_minutes) {
                return $type->default_duration_minutes;
            }
        }

        return $clinic->default_slot_duration_minutes ?? 30;
    }
}
