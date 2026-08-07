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
     * Defaults to Pending + PartiallyPaid (the open receivables); `filter[status]` narrows to
     * a single status to review Paid/Cancelled history too. `collected_total` is a withSum so
     * the controller can emit collected/remaining without an N+1.
     *
     * @return Collection<int, PaymentPlanInstallment>
     */
    public function pendingInstallmentsForClinic(): Collection
    {
        $status = request()->enum('filter.status', InstallmentStatus::class);
        $patientId = request()->integer('filter.patient_id') ?: null;
        $dueAfter = rescue(fn () => request()->date('filter.due_after'), null, report: false);
        $dueBefore = rescue(fn () => request()->date('filter.due_before'), null, report: false);

        return PaymentPlanInstallment::query()
            ->with('plan.patient')
            ->withSum('transactions as collected_total', 'amount')
            ->when(
                $status !== null,
                fn ($query) => $query->where('status', $status->value),
                fn ($query) => $query->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::PartiallyPaid->value]),
            )
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
     * Overdue/due-soon counts + totals for the active clinic's open (Pending + PartiallyPaid)
     * installments, ignoring the screen's own filters (a dashboard summary reads the true
     * totals). Totals sum the REMAINING amount, not the full installment amount, since a
     * partially-collected installment already has money against it.
     *
     * @return array{overdue_count: int, overdue_total: string, due_soon_count: int, due_soon_total: string}
     */
    public function pendingStatsForClinic(string $today, string $dueSoonEnd): array
    {
        $base = PaymentPlanInstallment::whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::PartiallyPaid->value])
            ->withSum('transactions as collected_total', 'amount');

        $overdue = (clone $base)->whereDate('due_date', '<', $today)->get();
        $dueSoon = (clone $base)->whereDate('due_date', '>=', $today)->whereDate('due_date', '<=', $dueSoonEnd)->get();

        $remainingOf = static fn (PaymentPlanInstallment $installment): string => bcsub(
            (string) $installment->amount,
            bcadd('0', (string) ($installment->collected_total ?? '0'), 2),
            2,
        );

        return [
            'overdue_count' => $overdue->count(),
            'overdue_total' => $overdue->reduce(fn (string $carry, PaymentPlanInstallment $i) => bcadd($carry, $remainingOf($i), 2), '0.00'),
            'due_soon_count' => $dueSoon->count(),
            'due_soon_total' => $dueSoon->reduce(fn (string $carry, PaymentPlanInstallment $i) => bcadd($carry, $remainingOf($i), 2), '0.00'),
        ];
    }

    /**
     * A patient's payment plans in the active clinic, newest first, with installments eager
     * loaded (already sequence-ordered by the model relation) and their collected total
     * pre-summed (`collected_total`) so the caller can derive collected/remaining without an
     * N+1 (mirrors pendingInstallmentsForClinic()'s withSum).
     *
     * @return Collection<int, PaymentPlan>
     */
    public function plansForPatient(int $patientId): Collection
    {
        return PaymentPlan::where('patient_id', $patientId)
            ->with(['installments' => fn ($query) => $query->withSum('transactions as collected_total', 'amount')])
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
            ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::PartiallyPaid->value])
            ->where($flagColumn, false)
            ->with(['plan.clinic', 'plan.patient'])
            // This wave spans every clinic, so the collected sum drops ClinicScope too —
            // otherwise a caller with an active clinic would read 0.00 for every other
            // clinic's installment and the reminder would quote the full amount as remaining.
            ->withSum([
                'transactions as collected_total' => fn ($query) => $query->withoutGlobalScope(ClinicScope::class),
            ], 'amount')
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

    /** Re-read an installment under a row lock (see PaymentPlanService::collect). */
    public function lockInstallmentForUpdate(int $id): PaymentPlanInstallment
    {
        return PaymentPlanInstallment::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }
}
