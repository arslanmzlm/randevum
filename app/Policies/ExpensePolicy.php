<?php

namespace App\Policies;

use App\Models\Expense;
use App\Models\User;

class ExpensePolicy
{
    /**
     * The finance page's all-clinic expense list (owner/manager). Also doubles as
     * the "manage ANY expense" gate in the ownership branches below — no dedicated
     * expenses.manage permission (GATE-1 resolution).
     */
    public function viewAny(User $user): bool
    {
        return $user->can('expenses.viewAny');
    }

    /**
     * Also the gate for reaching Giderlerim (the own-list page) — there is no
     * separate expenses.viewOwn permission; create alone unlocks the own-list.
     */
    public function create(User $user): bool
    {
        return $user->can('expenses.create');
    }

    public function update(User $user, Expense $expense): bool
    {
        return $expense->created_by === $user->id || $user->can('expenses.viewAny');
    }

    public function delete(User $user, Expense $expense): bool
    {
        return $expense->created_by === $user->id || $user->can('expenses.viewAny');
    }
}
