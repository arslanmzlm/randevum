<?php

namespace App\Policies;

use App\Models\User;

class ClinicSmsSettingPolicy
{
    /**
     * SetClinicContext already called setPermissionsTeamId($clinicId), so can()
     * is implicitly scoped to the active clinic.
     */
    public function view(User $user): bool
    {
        return $user->can('smsSettings.view');
    }

    public function update(User $user): bool
    {
        return $user->can('smsSettings.update');
    }
}
