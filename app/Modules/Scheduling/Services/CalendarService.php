<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ScheduleException;
use App\Models\User;
use App\Modules\Core\Contracts\DoctorDirectoryContract;
use App\Modules\Core\Services\ClinicMembershipService;
use App\Modules\Scheduling\Repositories\AppointmentRepository;
use App\Modules\Scheduling\Repositories\ScheduleExceptionRepository;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class CalendarService
{
    public function __construct(
        private AppointmentRepository $appointmentRepository,
        private ScheduleExceptionRepository $scheduleExceptionRepository,
        private DoctorDirectoryContract $doctorDirectory,
        private ClinicMembershipService $membership,
    ) {}

    /**
     * Build the calendar events payload for a given user, range and filters.
     *
     * Scope resolution:
     * - viewAll → all active clinic doctors, optionally narrowed by $doctorIds
     * - no viewAll → only the user's own doctor profile (null profile → empty)
     *
     * $clinicIds null (or exactly [$clinic->id]) keeps today's single-clinic path
     * byte-for-byte. Any other non-null value enters multi-branch mode: doctor
     * filtering is ignored (doctors are per-clinic), no leave/exception blocks are
     * returned, and each event is formatted in its OWN clinic's timezone. Multi-branch
     * mode requires appointments.viewAll — without it a user has exactly one `doctors`
     * row, so cross-branch is meaningless and the single-clinic path is used instead.
     *
     * @param  list<int>|null  $doctorIds
     * @param  list<AppointmentStatus>  $statuses
     * @param  list<int>|null  $clinicIds
     * @return array{data: list<array<string, mixed>>, exceptions: list<array<string, mixed>>}
     */
    public function eventsFor(
        User $user,
        Carbon $startUtc,
        Carbon $endUtc,
        ?array $doctorIds,
        array $statuses,
        Clinic $clinic,
        ?array $clinicIds = null,
    ): array {
        $multiBranch = $clinicIds !== null
            && $clinicIds !== [$clinic->id]
            && $user->can('appointments.viewAll');

        if ($multiBranch) {
            return $this->multiBranchEvents($user, $startUtc, $endUtc, $statuses, $clinicIds);
        }

        return $this->singleClinicEvents($user, $startUtc, $endUtc, $doctorIds, $statuses, $clinic);
    }

    /**
     * @param  list<int>|null  $doctorIds
     * @param  list<AppointmentStatus>  $statuses
     * @return array{data: list<array<string, mixed>>, exceptions: list<array<string, mixed>>}
     */
    private function singleClinicEvents(
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
            'data' => $appointments->map(fn (Appointment $a) => $this->eventDto($a, $tz, $clinic->id, $clinic->name))->values()->all(),
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

    /**
     * @param  list<int>  $clinicIds
     * @param  list<AppointmentStatus>  $statuses
     * @return array{data: list<array<string, mixed>>, exceptions: list<array<string, mixed>>}
     */
    private function multiBranchEvents(User $user, Carbon $startUtc, Carbon $endUtc, array $statuses, array $clinicIds): array
    {
        /** @var Collection<int, Clinic> $clinics */
        $clinics = $this->membership->clinicsFor($user)->whereIn('id', $clinicIds)->keyBy('id');

        $appointments = $this->appointmentRepository->inRangeForClinics($clinicIds, $startUtc, $endUtc, $statuses);

        return [
            'data' => $appointments->map(function (Appointment $a) use ($clinics) {
                $eventClinic = $clinics->get($a->clinic_id);

                // Each branch may run its own timezone — format per-event, not on
                // the active clinic's timezone.
                return $this->eventDto($a, $eventClinic?->timezone ?? 'UTC', $a->clinic_id, $eventClinic?->name ?? '');
            })->values()->all(),
            // The month grid renders no leave blocks in multi-branch mode.
            'exceptions' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventDto(Appointment $a, string $tz, int $clinicId, string $clinicName): array
    {
        return [
            'id' => $a->id,
            'doctor_id' => $a->doctor_id,
            'doctor_name' => $a->doctor->display_name,
            'title' => trim($a->patient->first_name.' '.$a->patient->last_name),
            // The popover links the name straight to the patient record.
            'patient_id' => $a->patient_id,
            'start' => $a->starts_at->setTimezone($tz)->format('Y-m-d H:i'),
            'end' => $a->ends_at->setTimezone($tz)->format('Y-m-d H:i'),
            // The grid positions events by clinic-local wall clock, but "is it past?" must be
            // decided on a real instant — the popover feeds this into the shared action rules.
            'starts_at_utc' => $a->starts_at->toIso8601String(),
            'status' => $a->status->value,
            'is_walk_in' => $a->is_walk_in,
            'service_name' => $a->service?->name,
            'type_name' => $a->appointmentType?->name,
            'type_color' => $a->appointmentType?->color,
            // Lets the popover route to "start treatment" vs "resume draft".
            'treatment_id' => $a->treatment?->id,
            'clinic_id' => $clinicId,
            'clinic_name' => $clinicName,
        ];
    }
}
