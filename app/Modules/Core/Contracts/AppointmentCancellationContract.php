<?php

namespace App\Modules\Core\Contracts;

use App\Models\User;

/**
 * Shared contract for cancelling a doctor's future appointments during offboarding.
 *
 * Lives in Core (shared kernel) so DoctorProfileService can depend on the
 * abstraction without importing Scheduling. AppointmentService implements it;
 * the binding lives in SchedulingServiceProvider.
 */
interface AppointmentCancellationContract
{
    /**
     * Cancel all future Confirmed/Rescheduled appointments for the given doctor.
     * Must be called from inside an outer DB::transaction — no inner transaction.
     * Returns the number of appointments cancelled (0 when none).
     */
    public function cancelFutureForDoctor(int $doctorProfileId, ?string $reason, User $actor): int;

    /**
     * Count upcoming cancellable appointments for the doctor profile.
     * Read-only — does not mutate state.
     */
    public function countCancellableFutureForDoctor(int $doctorProfileId): int;
}
