<?php

namespace App\Policies;

use App\Models\Doctor;
use App\Models\ScheduleException;
use App\Models\User;

class ScheduleExceptionPolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext has already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('scheduleExceptions.viewAny');
    }

    /**
     * A doctor may create an exception for their own profile (ownership branch).
     * Clinic-wide or other-doctor exceptions require the manage permission.
     *
     * $doctor is null for clinic-wide scope — no ownership match possible.
     */
    public function create(User $user, ?Doctor $doctor = null): bool
    {
        return $doctor?->user_id === $user->id || $user->can('scheduleExceptions.manage');
    }

    /**
     * A doctor may delete their own exceptions; otherwise the manage permission is required.
     */
    public function delete(User $user, ScheduleException $exception): bool
    {
        return $exception->doctor?->user_id === $user->id
            || $user->can('scheduleExceptions.manage');
    }
}
