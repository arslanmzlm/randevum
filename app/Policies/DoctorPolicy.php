<?php

namespace App\Policies;

use App\Models\Doctor;
use App\Models\User;

class DoctorPolicy
{
    /**
     * Any authenticated user with an active clinic context may view the list.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Owner or manager may add a new doctor to the clinic.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('owner') || $user->hasRole('manager');
    }

    /**
     * Owner may turn themselves into a doctor (solo-practitioner path).
     * SetClinicContext already called setPermissionsTeamId($clinicId).
     */
    public function createOwn(User $user): bool
    {
        return $user->hasRole('owner');
    }

    /**
     * Owner/manager may edit any doctor; a doctor may edit their own profile.
     */
    public function update(User $user, Doctor $doctor): bool
    {
        return $user->hasRole('owner') || $user->hasRole('manager') || $doctor->user_id === $user->id;
    }

    /**
     * Owner or manager may remove a doctor from the clinic.
     */
    public function delete(User $user, Doctor $doctor): bool
    {
        return $user->hasRole('owner') || $user->hasRole('manager');
    }
}
