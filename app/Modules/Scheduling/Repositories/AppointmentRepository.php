<?php

namespace App\Modules\Scheduling\Repositories;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Support\FilterHelper;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

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
     * Server-side paginated list of appointments for the active clinic, applying
     * request-driven search/filter/sort via FilterHelper.
     *
     * ClinicScope auto-isolates the tenant — no explicit clinic_id filter needed.
     *
     * @param  list<int>|null  $doctorIds  null = all doctors; non-null = constrain to these ids
     * @return LengthAwarePaginator<Appointment>
     */
    public function paginateForActiveClinic(?array $doctorIds, string $timezone): LengthAwarePaginator
    {
        $query = Appointment::query()
            ->with(['patient', 'doctor.user', 'service', 'appointmentType']);

        if ($doctorIds !== null) {
            $query->whereIn('doctor_id', $doctorIds);
        }

        $helper = FilterHelper::for($query)
            ->searchRelation('patient', 'first_name', 'last_name', 'phone')
            ->enumMultiple(['status' => AppointmentStatus::class])
            ->exact('doctor_id')
            ->dateRange('starts_at', 'start_date', 'end_date', $timezone);

        // User-supplied sort wins; fall back to newest-first by starts_at.
        if (request()->filled('sort')) {
            $helper->sort('starts_at', 'created_at', 'status');
        } else {
            $query->orderByDesc('starts_at');
        }

        return $helper->paginate();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Appointment $appointment, array $data): Appointment
    {
        $appointment->fill($data)->save();

        return $appointment;
    }

    /**
     * True when the doctor already has a Confirmed or Arrived appointment that
     * strictly overlaps the given slot (back-to-back slots are NOT a conflict).
     * Pass $excludeAppointmentId to ignore the appointment's own current slot (reschedule).
     */
    public function hasConflictingAppointment(int $doctorId, mixed $start, mixed $end, ?int $excludeAppointmentId = null): bool
    {
        return Appointment::forDoctor($doctorId)
            ->overlapping($start, $end)
            ->withStatus([AppointmentStatus::Confirmed, AppointmentStatus::Arrived])
            ->when($excludeAppointmentId, fn ($q, $id) => $q->whereKeyNot($id))
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
