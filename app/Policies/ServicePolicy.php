<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

class ServicePolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('services.viewAny');
    }

    public function view(User $user, Service $service): bool
    {
        return $user->can('services.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('services.create');
    }

    public function update(User $user, Service $service): bool
    {
        return $user->can('services.update');
    }

    public function delete(User $user, Service $service): bool
    {
        return $user->can('services.delete');
    }
}
