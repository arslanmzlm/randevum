<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

class PatientPolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('patients.viewAny');
    }

    public function view(User $user, Patient $patient): bool
    {
        return $user->can('patients.view');
    }

    public function create(User $user): bool
    {
        return $user->can('patients.create');
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->can('patients.update');
    }

    public function delete(User $user, Patient $patient): bool
    {
        return $user->can('patients.delete');
    }

    public function updateNotes(User $user, Patient $patient): bool
    {
        return $user->can('patients.note.update');
    }

    public function updateAnamnesis(User $user, Patient $patient): bool
    {
        return $user->can('anamnesis.update');
    }
}
