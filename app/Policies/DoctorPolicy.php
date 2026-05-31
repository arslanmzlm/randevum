<?php

namespace App\Policies;

use App\Models\Doctor;
use App\Models\User;

class DoctorPolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('doctors.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('doctors.create');
    }

    /**
     * Owner may turn themselves into a doctor (solo-practitioner path).
     */
    public function createOwn(User $user): bool
    {
        return $user->can('doctors.createOwn');
    }

    /**
     * A doctor may always edit their own profile; otherwise the manage permission
     * is required (ownership can't be expressed as a permission).
     */
    public function update(User $user, Doctor $doctor): bool
    {
        return $doctor->user_id === $user->id || $user->can('doctors.update');
    }

    public function delete(User $user, Doctor $doctor): bool
    {
        return $user->can('doctors.delete');
    }
}
