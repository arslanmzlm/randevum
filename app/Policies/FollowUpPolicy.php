<?php

namespace App\Policies;

use App\Models\FollowUp;
use App\Models\User;

class FollowUpPolicy
{
    /**
     * Manual follow-up creation, from the widget and the case panel.
     */
    public function create(User $user): bool
    {
        return $user->can('followUps.create');
    }

    /**
     * Complete ("Arandı") or cancel ("İptal") a follow-up.
     *
     * Two independent routes to the same ability: the front-desk `followUps.dismiss`
     * permission (clinic-wide), or — ownership logic that cannot be a permission — a doctor
     * completing a follow-up tied to their OWN case. Tenant isolation is guaranteed by
     * ClinicScope on route-model binding.
     */
    public function complete(User $user, FollowUp $followUp): bool
    {
        return $user->can('followUps.dismiss')
            || ($followUp->case_id !== null
                && $user->can('cases.update')
                && $followUp->caseRecord?->doctor_id === $user->doctor?->id);
    }
}
