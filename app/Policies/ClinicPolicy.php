<?php

namespace App\Policies;

use App\Models\Clinic;
use App\Models\User;
use App\Modules\Core\Services\ClinicMembershipService;
use App\Support\ClinicContext;

class ClinicPolicy
{
    public function __construct(
        private ClinicMembershipService $membership,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * SetClinicContext already called setPermissionsTeamId($clinicId), so can()
     * is implicitly scoped to the active clinic.
     */
    public function update(User $user, Clinic $clinic): bool
    {
        return $user->can('clinic.update');
    }

    /**
     * Gates the branch list, form and store — owner-only per the brief.
     */
    public function create(User $user): bool
    {
        return $user->can('clinics.create');
    }

    /**
     * The user must genuinely belong to the target clinic AND hold clinics.switch
     * either there or in the clinic they are currently in — the "or active clinic"
     * leg prevents lock-in for a user who is e.g. manager at A / receptionist at B.
     */
    public function switchTo(User $user, Clinic $clinic): bool
    {
        if (! $this->membership->belongsTo($user, $clinic->id)) {
            return false;
        }

        $targets = $this->membership->switchTargetsFor($user, $this->clinicContext->id());

        return in_array($clinic->id, $targets, true);
    }
}
