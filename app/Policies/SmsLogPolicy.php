<?php

namespace App\Policies;

use App\Models\User;

class SmsLogPolicy
{
    /**
     * SetClinicContext has already called setPermissionsTeamId($clinicId), so can()
     * is implicitly scoped to the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('smsLogs.viewAny');
    }
}
