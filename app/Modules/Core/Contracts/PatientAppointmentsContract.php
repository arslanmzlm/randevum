<?php

namespace App\Modules\Core\Contracts;

use App\Models\Appointment;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Read seam Medical uses to show a patient's appointment history on the patient
 * detail page. Lives in Core (shared kernel) so PatientController can depend on
 * the abstraction without importing Scheduling. AppointmentReader implements it.
 */
interface PatientAppointmentsContract
{
    /**
     * The patient's appointments, scoped to the user's visibility: users with
     * `appointments.viewAll` see every doctor's, others only their own doctor's
     * (no doctor profile → empty). Eager-loads doctor/service/type for display,
     * newest first.
     *
     * @return Collection<int, Appointment>
     */
    public function listForPatient(Patient $patient, User $user): Collection;
}
