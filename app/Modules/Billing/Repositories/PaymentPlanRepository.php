<?php

namespace App\Modules\Billing\Repositories;

use App\Enums\InstallmentStatus;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Scopes\ClinicScope;
use Illuminate\Database\Eloquent\Collection;

class PaymentPlanRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function createPlan(array $data): PaymentPlan
    {
        return PaymentPlan::create($data);
    }

    /**
     * @param  list<array{sequence: int, due_date: string, amount: float|string}>  $installments
     */
    public function insertInstallments(PaymentPlan $plan, array $installments): void
    {
        foreach ($installments as $row) {
            $plan->installments()->create([
                'sequence' => $row['sequence'],
                'due_date' => $row['due_date'],
                'amount' => $row['amount'],
                'status' => InstallmentStatus::Pending->value,
            ]);
        }
    }

    /**
     * Pending-installments collections screen for the active clinic. Status/patient/date
     * filters are read directly from the current request (mirrors SmsLogRepository's use
     * of FilterHelper) rather than a passed-in array — `patient_id` filters through the
     * `plan` relation since it isn't a column on this table, which plain FilterHelper::exact()
     * can't express, so it's applied here by hand instead.
     *
     * Defaults to Pending only (the screen's stated purpose); `filter[status]` broadens it
     * to review Paid/Cancelled history too.
     *
     * @return Collection<int, PaymentPlanInstallment>
     */
    public function pendingInstallmentsForClinic(): Collection
    {
        $status = request()->enum('filter.status', InstallmentStatus::class) ?? InstallmentStatus::Pending;
        $patientId = request()->integer('filter.patient_id') ?: null;
        $dueAfter = rescue(fn () => request()->date('filter.due_after'), null, report: false);
        $dueBefore = rescue(fn () => request()->date('filter.due_before'), null, report: false);

        return PaymentPlanInstallment::query()
            ->with('plan.patient')
            ->where('status', $status->value)
            ->when($patientId, fn ($query) => $query->whereHas(
                'plan',
                fn ($planQuery) => $planQuery->where('patient_id', $patientId)
            ))
            ->when($dueAfter, fn ($query) => $query->whereDate('due_date', '>=', $dueAfter->toDateString()))
            ->when($dueBefore, fn ($query) => $query->whereDate('due_date', '<=', $dueBefore->toDateString()))
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Overdue/due-soon counts + totals for the active clinic's Pending installments,
     * ignoring the screen's own filters (a dashboard summary reads the true totals).
     *
     * @return array{overdue_count: int, overdue_total: string, due_soon_count: int, due_soon_total: string}
     */
    public function pendingStatsForClinic(string $today, string $dueSoonEnd): array
    {
        $base = PaymentPlanInstallment::where('status', InstallmentStatus::Pending->value);

        $overdue = (clone $base)->whereDate('due_date', '<', $today);
        $dueSoon = (clone $base)->whereDate('due_date', '>=', $today)->whereDate('due_date', '<=', $dueSoonEnd);

        return [
            'overdue_count' => (int) $overdue->count(),
            'overdue_total' => number_format((float) $overdue->sum('amount'), 2, '.', ''),
            'due_soon_count' => (int) $dueSoon->count(),
            'due_soon_total' => number_format((float) $dueSoon->sum('amount'), 2, '.', ''),
        ];
    }

    /**
     * A patient's payment plans in the active clinic, newest first, with installments eager
     * loaded (already sequence-ordered by the model relation).
     *
     * @return Collection<int, PaymentPlan>
     */
    public function plansForPatient(int $patientId): Collection
    {
        return PaymentPlan::where('patient_id', $patientId)
            ->with('installments')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Installments due on the given clinic-local calendar date, across ALL clinics (the
     * reminder cron scans globally), not yet flagged for this reminder wave.
     *
     * @param  non-empty-string  $flagColumn  'reminder_7d_sent' or 'reminder_1d_sent'
     * @return Collection<int, PaymentPlanInstallment>
     */
    public function dueInstallments(string $onDate, string $flagColumn): Collection
    {
        return PaymentPlanInstallment::withoutGlobalScope(ClinicScope::class)
            ->whereDate('due_date', $onDate)
            ->where('status', InstallmentStatus::Pending->value)
            ->where($flagColumn, false)
            ->with(['plan.clinic', 'plan.patient'])
            ->get();
    }

    public function markReminderSent(PaymentPlanInstallment $installment, string $flagColumn): void
    {
        $installment->$flagColumn = true;
        $installment->save();
    }

    /**
     * True when every installment on the plan is Paid (none left Pending/Cancelled) —
     * drives the plan's auto-Completed transition after a collection.
     */
    public function allPaid(int $planId): bool
    {
        return ! PaymentPlanInstallment::where('payment_plan_id', $planId)
            ->where('status', '!=', InstallmentStatus::Paid->value)
            ->exists();
    }
}
