<?php

namespace App\Modules\Core\Contracts;

use App\Models\User;

/**
 * Read seam that allows HandleInertiaRequests (Core) to populate the shared
 * upcoming-appointments prop without importing Scheduling concretely.
 * AppointmentReader implements it; the binding lives in SchedulingServiceProvider.
 */
interface UpcomingAppointmentsContract
{
    /**
     * Next N upcoming appointments for the given user, scoped by permission:
     * - `appointments.viewAll` → clinic-wide
     * - otherwise → own doctor profile only; no profile → []
     *
     * @return list<array<string, mixed>>
     */
    public function upcomingFor(User $user, int $limit): array;
}
