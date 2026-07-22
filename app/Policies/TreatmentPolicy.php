<?php

namespace App\Policies;

use App\Models\Appointment;
use App\Models\Treatment;
use App\Models\User;

class TreatmentPolicy
{
    /**
     * Permissions are clinic-scoped: SetClinicContext has already called
     * setPermissionsTeamId($clinicId), so can() resolves against the active clinic.
     */
    public function viewAny(User $user): bool
    {
        return $user->can('treatments.viewAny');
    }

    public function view(User $user, Treatment $treatment): bool
    {
        return $user->can('treatments.viewAll') || $this->ownsTreatment($user, $treatment);
    }

    /**
     * Process screen requires the same visibility as view.
     */
    public function process(User $user, Treatment $treatment): bool
    {
        return $this->view($user, $treatment);
    }

    /**
     * Starting a treatment requires treatments.create AND the ability to see
     * the appointment (viewAll or own doctor profile).
     *
     * @param  array{0: Appointment}  $arguments
     */
    public function create(User $user, mixed ...$arguments): bool
    {
        if (! $user->can('treatments.create')) {
            return false;
        }

        $appointment = $arguments[0] ?? null;

        if ($appointment instanceof Appointment) {
            return $user->can('appointments.viewAll') || $appointment->doctor_id === $user->doctor?->id;
        }

        return true;
    }

    /**
     * Completing a treatment requires the same gate as starting one.
     */
    public function complete(User $user, Treatment $treatment): bool
    {
        if (! $user->can('treatments.create')) {
            return false;
        }

        return $user->can('appointments.viewAll') || $this->ownsTreatment($user, $treatment);
    }

    /**
     * Treatment media (photos/documents) is KVKK-min: doctor + assistant only,
     * regardless of treatments.viewAll — owner/manager/receptionist never see it.
     */
    public function viewMedia(User $user, Treatment $treatment): bool
    {
        return $user->can('treatments.media.view')
            && ($user->can('treatments.viewAll') || $this->ownsTreatment($user, $treatment));
    }

    public function uploadMedia(User $user, Treatment $treatment): bool
    {
        return $user->can('treatments.media.upload')
            && ($user->can('treatments.viewAll') || $this->ownsTreatment($user, $treatment));
    }

    public function deleteMedia(User $user, Treatment $treatment): bool
    {
        return $user->can('treatments.media.delete')
            && ($user->can('treatments.viewAll') || $this->ownsTreatment($user, $treatment));
    }

    /**
     * A user "owns" a treatment when it belongs to their doctor profile.
     */
    private function ownsTreatment(User $user, Treatment $treatment): bool
    {
        return $user->doctor?->id === $treatment->doctor_id;
    }
}
