<?php

namespace App\Policies;

use App\Models\FollowUpType;
use App\Models\User;

class FollowUpTypePolicy
{
    /**
     * Coarse `followUpTypes.manage` permission (tags.manage precedent) — the type-definition
     * CRUD is small and clinic-curated, so one permission covers the whole screen.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('followUpTypes.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('followUpTypes.manage');
    }

    public function update(User $user, FollowUpType $followUpType): bool
    {
        return $user->can('followUpTypes.manage');
    }

    public function delete(User $user, FollowUpType $followUpType): bool
    {
        return $user->can('followUpTypes.manage');
    }
}
