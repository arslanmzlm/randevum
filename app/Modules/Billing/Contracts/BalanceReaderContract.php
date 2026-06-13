<?php

namespace App\Modules\Billing\Contracts;

/**
 * Read seam between Medical and Billing for patient balance / transaction display.
 *
 * Medical calls this to get derived balance figures and transaction lists; it never
 * touches TransactionRepository or Transaction models directly.
 */
interface BalanceReaderContract
{
    /**
     * Sum of all transaction amounts for a patient in the active clinic.
     * Returns a decimal string (e.g. "150.00"). Balance is never stored — always derived.
     */
    public function paidTotalForPatient(int $patientId): string;

    /**
     * All transactions for a patient in the active clinic, newest first (paid_at DESC, id DESC).
     * Returns plain arrays so no Billing models leak across the boundary.
     *
     * @return array<int, array{
     *   id: int,
     *   paid_at: string,
     *   payment_method: string,
     *   amount: string,
     *   note: string|null,
     *   status: string,
     *   treatment_id: int|null,
     * }>
     */
    public function transactionsForPatient(int $patientId): array;
}
