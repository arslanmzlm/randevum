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
     * Book N follow-up appointments for the same doctor + patient, skipping conflicting slots.
     *
     * @param  array{
     *     doctor_id: int,
     *     patient_id: int,
     *     case_id: int|null,
     *     service_id: int|null,
     *     first_starts_at: string,
     *     count: int,
     *     interval: 'weekly'|'biweekly'|'monthly',
     * }  $criteria
     * @return array{created: list<Appointment>, skipped: list<string>}
     */
    public function scheduleFollowUps(array $criteria, User $actor): array;
}
