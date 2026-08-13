<?php

namespace App\Modules\Core\Contracts;

use App\Models\User;

/**
 * Read seam that lets DashboardController pull KPI tiles without importing
 * Scheduling or Billing modules concretely.
 * DashboardStatsService (Scheduling) implements it; binding lives in SchedulingServiceProvider.
 */
interface DashboardStatsContract
{
    /**
     * KPI tile data for the active clinic, gated per-block by user permissions.
     *
     * Each key is null when the user lacks the required permission:
     * - `appointments`   → requires `appointments.viewAny`
     * - `revenue`        → requires `transactions.viewAny`
     * - `today_schedule` → requires `appointments.viewAny` (the takvim-özet feed)
     *
     * `today_schedule` is the FULL clinic-local day for the visible doctor scope (no
     * now()-forward filter, no small-N cap) so the panel shows already-started/passed
     * rows too — it is NOT the forward-only `upcomingAppointments` shared prop.
     *
     * `today_collected` is a 2-dp decimal string (money is never a float over the wire).
     * `currency` comes from the active clinic's `currency` column (never hardcoded).
     *
     * @return array{
     *   appointments: array{today: int, pending: int, this_week: int, no_show_rate: array{percent: float, no_show: int, expected: int}|null}|null,
     *   revenue: array{today_collected: string, currency: string}|null,
     *   today_schedule: list<array{
     *     id: int, patient_id: int, patient_name: string, patient_is_deleted: bool,
     *     doctor_id: int, doctor_name: string, doctor_is_deleted: bool,
     *     service_name: string|null, status: string, is_walk_in: bool, starts_at: string,
     *   }>|null,
     * }
     */
    public function statsFor(User $user): array;
}
