<?php

namespace App\Modules\Identity\Services;

use App\Models\Clinic;
use App\Models\User;
use App\Support\ClinicContext;
use Spatie\Permission\PermissionRegistrar;

/**
 * Moves the session's active clinic to a new branch of the same (or a different)
 * membership, re-establishing the Spatie team context so can() resolves against
 * the new clinic on the very next check.
 */
class ClinicSwitchService
{
    public function switchTo(User $user, Clinic $clinic): void
    {
        session()->put('active_clinic_id', $clinic->id);

        app(ClinicContext::class)->set($clinic->id);
        app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);

        $user->unsetRelation('roles');
        $user->unsetRelation('permissions');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
