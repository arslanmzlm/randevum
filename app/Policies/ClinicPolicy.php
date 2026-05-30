<?php

namespace App\Policies;

use App\Models\Clinic;
use App\Models\User;

class ClinicPolicy
{
    /**
     * Only the clinic owner may view or edit the clinic profile.
     *
     * SetClinicContext already called setPermissionsTeamId($clinicId), so
     * hasRole('owner') is implicitly scoped to the active clinic.
     */
    public function update(User $user, Clinic $clinic): bool
    {
        return $user->hasRole('owner');
    }
}
