<?php

namespace App\Modules\Billing\Services;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\User;
use App\Modules\Billing\Repositories\PaymentPlanRepository;
use App\Modules\Core\Services\StatusLogService;
use Carbon\CarbonInterface;

/**
 * The single place that knows an installment's derived money state — collected, remaining,
 * and the status that follows from them. Used by both PaymentPlanService (collect) and
 * RefundService (reopen), so the two paths can never disagree (payments-stock: never store
 * balance, always derive it).
 */
class InstallmentSettlementService
{
    public function __construct(
        private StatusLogService $statusLogService,
        private PaymentPlanRepository $repository,
    ) {}

    /** Collected total for the installment — SUM(transactions.amount), refunds included. */
    public function collectedTotal(PaymentPlanInstallment $installment): string
    {
        return bcadd('0', (string) $installment->transactions()->sum('amount'), 2);
    }

    /**
     * Same derivation as collectedTotal(), but reads a preloaded `collected_total` attribute
     * (from a `withSum('transactions as collected_total', 'amount')` eager load) when present,
     * falling back to the query for callers that didn't preload it. Keeps the "how do we sum
     * collected" logic in one place regardless of whether the caller batched the sum.
     */
    public function collectedFromLoaded(PaymentPlanInstallment $installment): string
    {
        if (array_key_exists('collected_total', $installment->getAttributes())) {
            return bcadd('0', (string) ($installment->collected_total ?? '0'), 2);
        }

        return $this->collectedTotal($installment);
    }

    /** Remaining amount, never negative. */
    public function remaining(PaymentPlanInstallment $installment): string
    {
        $remaining = bcsub((string) $installment->amount, $this->collectedTotal($installment), 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }

    /**
     * Recompute the installment's status from its derived collected/remaining, log the
     * transition when it actually changes, and re-sync the parent plan. A Cancelled
     * installment is left untouched — cancelling is a separate, explicit transition.
     */
    public function sync(PaymentPlanInstallment $installment, User $actor, CarbonInterface|string|null $paidAt = null): void
    {
        if ($installment->status === InstallmentStatus::Cancelled) {
            return;
        }

        $collected = $this->collectedTotal($installment);
        $fromStatus = $installment->status;

        $newStatus = match (true) {
            bccomp($collected, (string) $installment->amount, 2) >= 0 => InstallmentStatus::Paid,
            bccomp($collected, '0', 2) > 0 => InstallmentStatus::PartiallyPaid,
            default => InstallmentStatus::Pending,
        };

        $installment->status = $newStatus;
        $installment->paid_at = $newStatus === InstallmentStatus::Paid ? ($paidAt ?? now()) : null;
        $installment->save();

        if ($fromStatus !== $newStatus) {
            $this->statusLogService->record($installment, $fromStatus->value, $newStatus->value, $actor);
        }

        $installment->loadMissing('plan');
        $this->syncPlanStatus($installment->plan, $actor);
    }

    /**
     * Recompute the plan's status from its installments: all Paid → Completed; otherwise a
     * Completed plan drops back to Active. A Cancelled plan is left untouched.
     */
    public function syncPlanStatus(PaymentPlan $plan, User $actor): void
    {
        if ($plan->status === PaymentPlanStatus::Cancelled) {
            return;
        }

        $newStatus = $this->repository->allPaid($plan->id) ? PaymentPlanStatus::Completed : PaymentPlanStatus::Active;

        if ($plan->status === $newStatus) {
            return;
        }

        $fromStatus = $plan->status;
        $plan->status = $newStatus;
        $plan->save();

        $this->statusLogService->record($plan, $fromStatus->value, $newStatus->value, $actor);
    }
}
