<?php

namespace App\Policies;

use App\Models\Clinic;
use App\Models\User;

class ClinicPolicy
{
    /**
     * SetClinicContext already called setPermissionsTeamId($clinicId), so can()
     * is implicitly scoped to the active clinic.
     */
    public function update(User $user, Clinic $clinic): bool
    {
        return $user->can('clinic.update');
    }
}
