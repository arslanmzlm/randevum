<?php

namespace App\Policies;

use App\Models\CaseRecord;
use App\Models\User;

class CasePolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext has already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('cases.viewAny');
    }

    public function view(User $user, CaseRecord $case): bool
    {
        return $user->can('cases.viewAll') || $this->ownsCase($user, $case);
    }

    public function create(User $user): bool
    {
        return $user->can('cases.create');
    }

    /**
     * Used for: changeStatus, updateNotes, updateTitle, linkTreatments, unlinkTreatment.
     * Treatment-level ownership is re-checked in the service.
     */
    public function update(User $user, CaseRecord $case): bool
    {
        return $user->can('cases.update')
            && ($user->can('cases.viewAll') || $this->ownsCase($user, $case));
    }

    /**
     * A user "owns" a case when it belongs to their doctor profile.
     */
    private function ownsCase(User $user, CaseRecord $case): bool
    {
        return $user->doctor?->id === $case->doctor_id;
    }
}
