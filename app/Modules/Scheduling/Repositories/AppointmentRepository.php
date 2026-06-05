<?php

namespace App\Modules\Scheduling\Repositories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;

class AppointmentRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Appointment
    {
        return Appointment::create($data);
    }

    /**
     * True when the doctor already has a Confirmed or Arrived appointment that
     * strictly overlaps the given slot (back-to-back slots are NOT a conflict).
     */
    public function hasConflictingAppointment(int $doctorId, mixed $start, mixed $end): bool
    {
        return Appointment::forDoctor($doctorId)
            ->overlapping($start, $end)
            ->withStatus([AppointmentStatus::Confirmed, AppointmentStatus::Arrived])
            ->exists();
    }
}
