<?php

namespace App\Policies;

use App\Models\AppointmentType;
use App\Models\User;

class AppointmentTypePolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('appointmentTypes.viewAny');
    }

    public function view(User $user, AppointmentType $appointmentType): bool
    {
        return $user->can('appointmentTypes.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('appointmentTypes.create');
    }

    public function update(User $user, AppointmentType $appointmentType): bool
    {
        return $user->can('appointmentTypes.update');
    }

    public function delete(User $user, AppointmentType $appointmentType): bool
    {
        return $user->can('appointmentTypes.delete');
    }
}
