<?php

namespace App\Modules\Scheduling\Contracts;

/**
 * Deliberately separate from PatientAppointmentsContract: the deletion guard is a read-only
 * count that must not drag in AppointmentService's full dependency chain (which depends back
 * on Medical's PatientRegistrarContract, closing a container resolution cycle).
 * PatientAppointmentCounter implements it; the binding lives in SchedulingServiceProvider.
 */
interface PatientAppointmentCounterContract
{
    /**
     * Count of the patient's future active appointments (any doctor, any status except
     * Cancelled/Completed/NoShow). Used to block patient deletion — unlike listForPatient(),
     * NOT scoped to the caller's doctor visibility: a deletion guard must see every
     * appointment regardless of who's asking.
     */
    public function countFutureForPatient(int $patientId): int;
}
