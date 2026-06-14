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
            ->with(['patient', 'doctor.user', 'service', 'appointmentType', 'treatment']);

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
     * Appointments whose starts_at falls in [fromUtc, toUtc), filtered to the given
     * cancellable statuses and optionally narrowed by doctor(s).
     *
     * ClinicScope auto-isolates the tenant — no explicit clinic_id filter needed.
     * Passing $doctorIds = null returns all clinic doctors; [] returns an empty collection.
     *
     * @param  list<int>|null  $doctorIds  null = all doctors
     * @param  list<AppointmentStatus>  $statuses
     * @return Collection<int, Appointment>
     */
    public function cancellableStartingBetween(
        ?array $doctorIds,
        mixed $fromUtc,
        mixed $toUtc,
        array $statuses,
    ): Collection {
        return Appointment::startingBetween($fromUtc, $toUtc)
            ->withStatus($statuses)
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('doctor_id', $doctorIds))
            ->with(['patient', 'doctor.user', 'service'])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Future Confirmed/Rescheduled appointments for the given doctor, starting now or later.
     *
     * ClinicScope auto-isolates the tenant — no explicit clinic_id filter needed.
     *
     * @param  list<AppointmentStatus>  $statuses
     * @return Collection<int, Appointment>
     */
    public function cancellableFutureForDoctor(int $doctorId, array $statuses): Collection
    {
        return Appointment::forDoctor($doctorId)
            ->withStatus($statuses)
            ->where('starts_at', '>=', now())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Count future cancellable appointments for the doctor. Read-only — no eager loads.
     *
     * ClinicScope auto-isolates the tenant — no explicit clinic_id filter needed.
     *
     * @param  list<AppointmentStatus>  $statuses
     */
    public function countCancellableFutureForDoctor(int $doctorId, array $statuses): int
    {
        return Appointment::forDoctor($doctorId)
            ->withStatus($statuses)
            ->where('starts_at', '>=', now())
            ->count();
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
            ->with(['patient', 'service', 'appointmentType', 'doctor.user', 'treatment'])
            ->orderBy('starts_at');

        if (is_array($doctorIds)) {
            $query->whereIn('doctor_id', $doctorIds);
        } else {
            $query->forDoctor($doctorIds);
        }

        return $query->get();
    }

    /**
     * Next N upcoming appointments starting at or after now(), ascending.
     * ClinicScope auto-isolates the tenant — no explicit clinic_id filter needed.
     *
     * @param  list<int>|null  $doctorIds  null = all clinic doctors; [] = empty result (whereIn short-circuits)
     * @param  list<AppointmentStatus>  $statuses
     * @return Collection<int, Appointment>
     */
    public function upcomingForDoctors(?array $doctorIds, array $statuses, int $limit): Collection
    {
        return Appointment::query()
            ->withStatus($statuses)
            ->where('starts_at', '>=', now())
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('doctor_id', $doctorIds))
            ->with(['patient', 'doctor.user', 'service', 'appointmentType'])
            ->orderBy('starts_at')
            ->limit($limit)
            ->get();
    }

    /**
     * A patient's appointments for the patient-detail history, newest first.
     * ClinicScope is applied automatically.
     *
     * @param  int|null  $doctorId  null = all doctors; non-null = constrain (own/all)
     * @return Collection<int, Appointment>
     */
    public function forPatient(int $patientId, ?int $doctorId): Collection
    {
        $query = Appointment::where('patient_id', $patientId)
            ->with(['doctor.user', 'service', 'appointmentType'])
            ->orderByDesc('starts_at');

        if ($doctorId !== null) {
            $query->forDoctor($doctorId);
        }

        return $query->get();
    }
}
