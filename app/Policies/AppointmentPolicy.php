<?php

namespace App\Policies;

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
}
