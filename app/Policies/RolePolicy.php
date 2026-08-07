<?php

namespace App\Policies;

use App\Models\User;

class RolePolicy
{
    /**
     * Only viewAny this wave — roles.manage is seeded but has no policy method until
     * 8b enforces the editing/custom-role surface.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('roles.viewAny');
    }
}
