<?php

namespace App\Policies;

use App\Models\PaymentPlan;
use App\Models\User;

class PaymentPlanPolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('paymentPlans.viewAny');
    }

    public function create(User $user): bool
    {
        return $user->can('paymentPlans.create');
    }

    public function cancel(User $user, PaymentPlan $plan): bool
    {
        return $user->can('paymentPlans.cancel');
    }

    public function sendReminder(User $user, PaymentPlan $plan): bool
    {
        return $user->can('paymentPlans.sendReminder');
    }
}
