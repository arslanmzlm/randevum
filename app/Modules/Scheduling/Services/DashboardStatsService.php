<?php

namespace App\Modules\Scheduling\Services;

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\User;
use App\Modules\Billing\Contracts\DailyRevenueContract;
use App\Modules\Scheduling\Contracts\DashboardStatsContract;
use App\Modules\Scheduling\Repositories\AppointmentRepository;
use App\Support\ClinicContext;

class DashboardStatsService implements DashboardStatsContract
{
    public function __construct(
        private AppointmentRepository $repository,
        private DailyRevenueContract $dailyRevenue,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function statsFor(User $user): array
    {
        $clinicId = $this->clinicContext->id();

        if ($clinicId === null) {
            return ['appointments' => null, 'revenue' => null, 'today_schedule' => null];
        }

        $clinic = $this->clinicContext->clinicOrFail();

        return [
            'appointments' => $this->appointmentStats($user, $clinic->timezone),
            'revenue' => $this->revenueStats($user, $clinic),
            'today_schedule' => $this->todaySchedule($user, $clinic->timezone),
        ];
    }

    /**
     * The takvim-özet feed: the full clinic-local day for the visible doctor scope,
     * or null when the user lacks the gate. Doctor-scoping mirrors appointmentStats.
     *
     * Dedicated feed (not the forward-only `upcomingAppointments` prop) so the panel
     * also surfaces earlier-today rows (Arrived/Completed) and is not hidden by the
     * upcoming widget's small-N cap.
     *
     * @return list<array{
     *   id: int, patient_id: int, patient_name: string, patient_is_deleted: bool,
     *   doctor_id: int, doctor_name: string, doctor_is_deleted: bool,
     *   service_name: string|null, status: string, is_walk_in: bool, starts_at: string,
     * }>|null
     */
    private function todaySchedule(User $user, string $timezone): ?array
    {
        if (! $user->can('appointments.viewAny')) {
            return null;
        }

        return $this->repository->scheduleForToday($this->visibleDoctorIds($user), $timezone)
            ->filter(fn (Appointment $a): bool => $a->patient !== null)
            ->map(fn (Appointment $a): array => [
                'id' => $a->id,
                'patient_id' => $a->patient_id,
                'patient_name' => trim($a->patient->first_name.' '.$a->patient->last_name),
                'patient_is_deleted' => $a->patient->trashed(),
                'doctor_id' => $a->doctor_id,
                'doctor_name' => $a->doctor->display_name,
                'doctor_is_deleted' => $a->doctor->trashed(),
                'service_name' => $a->service?->name,
                'status' => $a->status->value,
                'is_walk_in' => $a->is_walk_in,
                'starts_at' => $a->starts_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Returns the appointment count tiles, or null when the user lacks the gate.
     *
     * @return array{today: int, pending: int, this_week: int, no_show_rate: array{percent: float, no_show: int, expected: int}|null}|null
     */
    private function appointmentStats(User $user, string $timezone): ?array
    {
        if (! $user->can('appointments.viewAny')) {
            return null;
        }

        $doctorIds = $this->visibleDoctorIds($user);

        return [
            'today' => $this->repository->countTodayForDoctors($doctorIds, $timezone),
            'pending' => $this->repository->countPendingForDoctors($doctorIds),
            'this_week' => $this->repository->countThisWeekForDoctors($doctorIds, $timezone),
            'no_show_rate' => $this->noShowRate($doctorIds, $timezone),
        ];
    }

    /**
     * This-month no-show rate: NoShow / (NoShow + Completed + Arrived), doctor-scoped
     * exactly like the other appointment tiles. Null when the denominator is 0 —
     * there is nothing resolved yet this month to compute a rate from.
     *
     * @param  list<int>|null  $doctorIds
     * @return array{percent: float, no_show: int, expected: int}|null
     */
    private function noShowRate(?array $doctorIds, string $timezone): ?array
    {
        $noShow = $this->repository->countNoShowThisMonth($doctorIds, $timezone);
        $expected = $this->repository->countExpectedThisMonth($doctorIds, $timezone);

        if ($expected === 0) {
            return null;
        }

        return [
            'percent' => round($noShow / $expected * 100, 1),
            'no_show' => $noShow,
            'expected' => $expected,
        ];
    }

    /**
     * Returns the revenue tile, or null when the user lacks the gate.
     * Revenue is always clinic-wide regardless of doctor scope (no per-doctor split
     * in the data model — transactions key off patient/treatment, not doctor).
     *
     * @return array{today_collected: string, currency: string}|null
     */
    private function revenueStats(User $user, Clinic $clinic): ?array
    {
        if (! $user->can('transactions.viewAny')) {
            return null;
        }

        return [
            'today_collected' => $this->dailyRevenue->collectedTodayForActiveClinic($clinic->timezone),
            'currency' => $clinic->currency,
        ];
    }

    /**
     * Doctor-scoping shared by every appointment-scoped block, mirroring the exact
     * branch from AppointmentReader::upcomingFor:
     * - `appointments.viewAll` → null (no doctor filter, clinic-wide)
     * - else → own doctor profile id; no profile → [] (empty scope → 0 rows)
     *
     * @return list<int>|null
     */
    private function visibleDoctorIds(User $user): ?array
    {
        if ($user->can('appointments.viewAll')) {
            return null;
        }

        $ownId = $user->doctor?->id;

        return $ownId !== null ? [$ownId] : [];
    }
}
