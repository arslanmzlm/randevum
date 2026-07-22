<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;

class TagPolicy
{
    /**
     * Coarse `tags.manage` permission (GATE-1 OPEN-1) — the CRUD is tiny, unlike the
     * granular viewAny/create/update/delete precedent (appointment types). Reception
     * applies existing tags via patients.update; tag-DEFINITION CRUD stays owner/manager
     * to preserve the curated, anti-drift list.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('tags.manage');
    }

    public function create(User $user): bool
    {
        return $user->can('tags.manage');
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->can('tags.manage');
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->can('tags.manage');
    }
}
