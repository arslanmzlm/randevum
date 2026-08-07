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

    /** Manuel gelir listesi (the /incomes list is the only viewAny reader today). */
    public function viewAny(User $user): bool
    {
        return $user->can('transactions.viewAny');
    }

    /**
     * Only a manual (patient-less) row is deletable, only inside the immutability window,
     * and only by its recorder or a user who may reverse money at all.
     */
    public function delete(User $user, Transaction $transaction): bool
    {
        return $transaction->patient_id === null
            && $transaction->created_at->diffInSeconds(now()) <= config('platform.edit_windows.transaction_delete')
            && ($transaction->created_by === $user->id || $user->can('transactions.refund'));
    }

    public function refund(User $user, Transaction $transaction): bool
    {
        return $user->can('transactions.refund');
    }
}
