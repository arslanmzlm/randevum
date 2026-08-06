<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Clinic;
use App\Models\ScheduleException;
use App\Models\User;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Scheduling\Repositories\ScheduleExceptionRepository;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ScheduleExceptionService
{
    public function __construct(
        private ScheduleExceptionRepository $repository,
        private DoctorDirectoryContract $doctorDirectory,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * Create one or more schedule exceptions from validated form data.
     *
     * scope=doctor → one row for the given doctor_id.
     * scope=clinic  → one row per active clinic doctor, wrapped in a transaction.
     *
     * Times in $data are in the clinic's local timezone; stored UTC.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws \Throwable
     */
    public function store(array $data, User $actor): void
    {
        $clinic = $this->clinicContext->clinicOrFail();

        if ($data['scope'] === 'clinic') {
            // Conflicting-appointment preview + cancel handled when appointments exist
            DB::transaction(function () use ($data, $actor, $clinic): void {
                foreach ($this->doctorDirectory->activeForClinic() as $doctor) {
                    $this->createOne($data, $doctor->id, $actor, $clinic);
                }
            });
        } else {
            $this->createOne($data, (int) $data['doctor_id'], $actor, $clinic);
        }
    }

    /**
     * Exceptions for the active clinic; upcoming only unless $includePast.
     *
     * @return Collection<int, ScheduleException>
     */
    public function listForClinic(bool $includePast = false): Collection
    {
        return $this->repository->listForClinic(includePast: $includePast);
    }

    /** Count of past exceptions for the active clinic — drives the "show past" hint. */
    public function pastCountForClinic(): int
    {
        return $this->repository->pastCountForClinic();
    }

    public function delete(ScheduleException $exception): void
    {
        $this->repository->delete($exception);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOne(array $data, int $doctorId, User $actor, Clinic $clinic): ScheduleException
    {
        $isAllDay = (bool) ($data['is_all_day'] ?? false);

        [$startsAt, $endsAt] = $this->normalizeRange(
            $data['starts_at'],
            $data['ends_at'],
            $isAllDay,
            $clinic->timezone,
        );

        return $this->repository->create([
            'doctor_id' => $doctorId,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'is_all_day' => $isAllDay,
            'reason' => $data['reason'] ?? null,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Parse start/end strings in the clinic timezone, then convert to UTC.
     * For all-day: clamp to startOfDay / endOfDay in the clinic timezone.
     *
     * @return array{Carbon, Carbon}
     */
    private function normalizeRange(string $rawStart, string $rawEnd, bool $isAllDay, string $timezone): array
    {
        $startsAt = Carbon::parse($rawStart, $timezone);
        $endsAt = Carbon::parse($rawEnd, $timezone);

        if ($isAllDay) {
            $startsAt->startOfDay();
            $endsAt->endOfDay();
        }

        return [$startsAt->utc(), $endsAt->utc()];
    }
}
