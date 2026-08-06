<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ScheduleException;
use App\Models\User;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Scheduling\Repositories\AppointmentRepository;
use App\Modules\Scheduling\Repositories\ScheduleExceptionRepository;
use Carbon\Carbon;

class CalendarService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private ScheduleExceptionRepository $scheduleExceptionRepository,
        private DoctorDirectoryContract $doctorDirectory,
    ) {}

    /**
     * Build the calendar events payload for a given user, range and filters.
     *
     * Scope resolution:
     * - viewAll → all active clinic doctors, optionally narrowed by $doctorIds
     * - no viewAll → only the user's own doctor profile (null profile → empty)
     *
     * @param  list<int>|null  $doctorIds
     * @param  list<AppointmentStatus>  $statuses
     * @return array{data: list<array<string, mixed>>, exceptions: list<array<string, mixed>>}
     */
    public function eventsFor(
        User $user,
        Carbon $startUtc,
        Carbon $endUtc,
        ?array $doctorIds,
        array $statuses,
        Clinic $clinic,
    ): array {
        $tz = $clinic->timezone;

        if ($user->can('appointments.viewAll')) {
            $doctorIds = $doctorIds ?? $this->doctorDirectory->activeForClinic()->pluck('id')->all();
        } else {
            $ownId = $user->doctor?->id;

            if ($ownId === null) {
                return ['data' => [], 'exceptions' => []];
            }

            $doctorIds = [$ownId];
        }

        $appointments = $this->appointmentRepository->inRange($doctorIds, $startUtc, $endUtc, $statuses);
        $exceptions = $this->scheduleExceptionRepository->inRange($doctorIds, $startUtc, $endUtc);

        return [
            'data' => $appointments->map(fn (Appointment $a) => [
                'id' => $a->id,
                'doctor_id' => $a->doctor_id,
                'doctor_name' => $a->doctor->display_name,
                'title' => trim($a->patient->first_name.' '.$a->patient->last_name),
                // The popover links the name straight to the patient record.
                'patient_id' => $a->patient_id,
                'start' => $a->starts_at->setTimezone($tz)->format('Y-m-d H:i'),
                'end' => $a->ends_at->setTimezone($tz)->format('Y-m-d H:i'),
                'status' => $a->status->value,
                'is_walk_in' => $a->is_walk_in,
                'service_name' => $a->service?->name,
                'type_name' => $a->appointmentType?->name,
                'type_color' => $a->appointmentType?->color,
                // Lets the popover route to "start treatment" vs "resume draft".
                'treatment_id' => $a->treatment?->id,
            ])->values()->all(),
            'exceptions' => $exceptions->map(fn (ScheduleException $e) => [
                'id' => $e->id,
                'doctor_id' => $e->doctor_id,
                'doctor_name' => $e->doctor->display_name,
                'start' => $e->starts_at->setTimezone($tz)->format('Y-m-d H:i'),
                'end' => $e->ends_at->setTimezone($tz)->format('Y-m-d H:i'),
                'reason' => $e->reason,
            ])->values()->all(),
        ];
    }
}
