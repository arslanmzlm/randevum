<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext has already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('appointments.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('appointments.create');
    }

    public function update(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.update') && $this->actsOnAccessible($user, $appointment);
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.cancel') && $this->actsOnAccessible($user, $appointment);
    }

    public function delete(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.delete') && $this->actsOnAccessible($user, $appointment);
    }

    /**
     * A user "acts on" an appointment when they can see all doctors' appointments,
     * or when the appointment belongs to their own doctor profile.
     * Ownership cannot be expressed as a permission — so it lives in the policy.
     */
    private function actsOnAccessible(User $user, Appointment $appointment): bool
    {
        return $user->can('appointments.viewAll') || $appointment->doctor_id === $user->doctor?->id;
    }
}
