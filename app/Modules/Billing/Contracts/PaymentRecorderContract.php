<?php

namespace App\Modules\Billing\Contracts;

use App\Models\Transaction;
use App\Models\User;

/**
 * Payment recording seam between Medical and Billing.
 *
 * Medical calls this after completing a treatment; it never touches Transaction
 * or TransactionRepository directly. PaymentService implements it.
 */
interface PaymentRecorderContract
{
    /**
     * Create a Completed transaction for the given payment input.
     *
     * @param  array{
     *     amount: float|string,
     *     payment_method: string,
     *     patient_id: int,
     *     treatment_id: int|null,
     *     note?: string|null,
     *     paid_at?: string|\DateTimeInterface|null,
     *     payment_plan_installment_id?: int|null,
     * }  $data
     */
    public function record(array $data, User $actor): Transaction;
}
