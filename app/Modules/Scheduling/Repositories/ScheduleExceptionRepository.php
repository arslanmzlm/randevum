<?php

namespace App\Modules\Scheduling\Repositories;

use App\Models\ScheduleException;
use Illuminate\Database\Eloquent\Collection;

class ScheduleExceptionRepository
{
    /**
     * Exceptions for the active clinic, with doctor and creator loaded.
     * Defaults to upcoming only (ends_at >= now); pass $includePast to also list past rows.
     * Optionally filtered to a single doctor.
     *
     * ClinicScope on ScheduleException already limits results to the active clinic.
     *
     * @return Collection<int, ScheduleException>
     */
    public function listForClinic(?int $doctorId = null, bool $includePast = false): Collection
    {
        return ScheduleException::with(['doctor.user', 'creator'])
            ->when(! $includePast, fn ($q) => $q->where('ends_at', '>=', now()))
            ->when($doctorId !== null, fn ($q) => $q->forDoctor($doctorId))
            ->orderBy('starts_at')
            ->get();
    }

    /** Count of past exceptions (ends_at < now) for the active clinic — drives the "show past" hint. */
    public function pastCountForClinic(): int
    {
        return ScheduleException::where('ends_at', '<', now())->count();
    }

    /**
     * Whether any exception for the doctor strictly overlaps [start, end] — back-to-back
     * with an exception boundary is not a conflict. ClinicScope is applied automatically.
     */
    public function existsOverlapping(int $doctorId, mixed $start, mixed $end): bool
    {
        return ScheduleException::forDoctor($doctorId)
            ->where('starts_at', '<', $end)
            ->where('ends_at', '>', $start)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): ScheduleException
    {
        return ScheduleException::create($data);
    }

    public function delete(ScheduleException $exception): void
    {
        $exception->delete();
    }

    /**
     * Schedule exceptions that overlap [startUtc, endUtc] for the given doctor(s).
     * ClinicScope is applied automatically.
     *
     * @param  int|list<int>  $doctorIds
     * @return Collection<int, ScheduleException>
     */
    public function inRange(int|array $doctorIds, mixed $startUtc, mixed $endUtc): Collection
    {
        $query = ScheduleException::overlapping($startUtc, $endUtc)
            ->orderBy('starts_at');

        if (is_array($doctorIds)) {
            $query->whereIn('doctor_id', $doctorIds);
        } else {
            $query->forDoctor($doctorIds);
        }

        return $query->get();
    }
}
