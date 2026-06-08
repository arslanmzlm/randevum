<?php

namespace App\Modules\Scheduling\Repositories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Collection;

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

    /**
     * All appointments that strictly overlap [startUtc, endUtc) for the given doctor(s),
     * filtered to the provided statuses. ClinicScope is applied automatically.
     *
     * @param  int|list<int>  $doctorIds
     * @param  list<AppointmentStatus>  $statuses
     * @return Collection<int, Appointment>
     */
    public function inRange(int|array $doctorIds, mixed $startUtc, mixed $endUtc, array $statuses): Collection
    {
        $query = Appointment::inRange($startUtc, $endUtc)
            ->withStatus($statuses)
            ->with(['patient', 'service', 'appointmentType', 'doctor.user'])
            ->orderBy('starts_at');

        if (is_array($doctorIds)) {
            $query->whereIn('doctor_id', $doctorIds);
        } else {
            $query->forDoctor($doctorIds);
        }

        return $query->get();
    }
}
