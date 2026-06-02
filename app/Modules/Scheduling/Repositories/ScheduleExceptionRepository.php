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
}
