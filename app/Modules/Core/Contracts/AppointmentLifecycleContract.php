<?php

namespace App\Modules\Core\Contracts;

use App\Models\Appointment;
use App\Models\User;

/**
 * Lifecycle transitions Medical calls on appointments during treatment processing.
 *
 * Lives in Core (shared kernel) so TreatmentService can depend on the abstraction
 * without importing Scheduling directly. AppointmentService implements it.
 */
interface AppointmentLifecycleContract
{
    /**
     * Transition a Confirmed or Rescheduled appointment to Arrived, recording the log.
     * No-op if already Arrived. Must be called inside a DB::transaction.
     *
     * Rescheduled → Arrived is a deliberate extension: a rescheduled patient who checks
     * in skips the Confirmed step because the appointment already exists.
     */
    public function markArrived(Appointment $appointment, User $actor): void;

    /**
     * Transition an Arrived appointment to Completed, set appointments.case_id, and log.
     * Must be called inside a DB::transaction.
     */
    public function markCompleted(Appointment $appointment, User $actor, ?int $caseId = null): void;

    /**
     * Book follow-up appointments from an explicit occurrence list, skipping conflicting slots.
     *
     * Each occurrence is a clinic-local datetime string (YYYY-MM-DDTHH:mm:ss or equivalent)
     * plus its own optional appointment type and duration (a package may mix per session).
     * Occurrences are sorted ascending before processing; duplicates resolve naturally —
     * the first books, the second hits the overlap check and is skipped.
     * Cap of 12 is enforced as defence-in-depth (FormRequest already validates this).
     *
     * @param  array{
     *     doctor_id: int,
     *     patient_id: int,
     *     case_id: int|null,
     *     service_id: int|null,
     *     occurrences: list<array{starts_at: string, duration_minutes?: int|null, appointment_type_id?: int|null}>,
     * }  $criteria
     * @return array{created: list<Appointment>, skipped: list<string>}
     */
    public function scheduleFollowUps(array $criteria, User $actor): array;
}
