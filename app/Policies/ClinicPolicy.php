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
     * Membership IS the right to switch: a user can only reach a clinic they already hold a
     * role in, and once there they work with that clinic's role, so the switch opens no data
     * the role assignment did not already grant. Restricting a person to one branch is done
     * by not assigning them a role in the other one.
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
