<?php

namespace App\Policies;

use App\Models\PatientSegment;
use App\Models\User;

class PatientSegmentPolicy
{
    /**
     * Coarse `segments.manage` permission (GATE-1 OPEN-1). Segments are clinic-shared
     * (GATE-1 OPEN-2) — any patients.viewAny user reads/applies them via the list page;
     * only create/delete are gated.
     */
    public function create(User $user): bool
    {
        return $user->can('segments.manage');
    }

    public function delete(User $user, PatientSegment $segment): bool
    {
        return $user->can('segments.manage');
    }
}
