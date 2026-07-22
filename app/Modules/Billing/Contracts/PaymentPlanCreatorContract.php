<?php

namespace App\Modules\Billing\Contracts;

use App\Models\User;

/**
 * Payment-plan creation seam between Medical and Billing — mirrors PaymentRecorderContract.
 * Medical calls this from the treatment Process (installment mode) after computing the
 * treatment total; it never touches PaymentPlan or PaymentPlanRepository directly.
 */
interface PaymentPlanCreatorContract
{
    /**
     * Create a payment plan + its installments in one transaction. Server-side sum-check
     * (Σ installments + down_payment == total_amount) is re-guarded here even when the
     * caller already validated it. Down payment (if any) is collected immediately, not
     * linked to any installment.
     *
     * @param  array{
     *     patient_id: int,
     *     treatment_id: int|null,
     *     total_amount: float|string,
     *     down_payment: float|string|null,
     *     down_payment_method: string|null,
     *     installment_count: int,
     *     installments: list<array{sequence: int, due_date: string, amount: float|string}>,
     * }  $data
     */
    public function create(array $data, User $actor): void;
}
