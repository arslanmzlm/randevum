<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Clinic;
use App\Models\ScheduleException;
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
     * Combined check: layer 1 always; layers 2 & 3 only when !$isWalkIn.
     */
    public function isAvailable(int $doctorId, mixed $start, mixed $end, bool $isWalkIn, Clinic $clinic): bool
    {
        if (! $this->insideWorkingHours($clinic, $start, $end)) {
            return false;
        }

        if ($isWalkIn) {
            return true;
        }

        if ($this->hasScheduleExceptionOverlap($doctorId, $start, $end)) {
            return false;
        }

        if ($this->appointmentRepository->hasConflictingAppointment($doctorId, $start, $end)) {
            return false;
        }

        return true;
    }
}
