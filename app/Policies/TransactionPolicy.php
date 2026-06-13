<?php

namespace App\Policies;

use App\Models\Transaction;
use App\Models\User;

class TransactionPolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function create(User $user): bool
    {
        return $user->can('transactions.create');
    }

    public function refund(User $user, Transaction $transaction): bool
    {
        return $user->can('transactions.refund');
    }
}
