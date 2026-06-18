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
     * Paid total (sum of transaction amounts) per patient in the active clinic, keyed by
     * patient_id, in one grouped query. Patients with no payments are absent from the map.
     * Lets Medical derive list-page balances without touching Billing tables.
     *
     * @param  array<int, int>  $patientIds
     * @return array<int, string> patient_id => paid total (decimal string)
     */
    public function paidTotalsForPatients(array $patientIds): array;

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
     *   original_transaction_id: int|null,
     *   refundable_amount: string,
     * }>
     */
    public function transactionsForPatient(int $patientId): array;

    /**
     * All transactions for a treatment in the active clinic, newest first (paid_at DESC, id DESC).
     * Same plain-array shape as {@see transactionsForPatient()} so no Billing models leak across
     * the boundary; Medical's treatment Show reads this instead of touching Transaction directly.
     *
     * @return array<int, array{
     *   id: int,
     *   paid_at: string,
     *   payment_method: string,
     *   amount: string,
     *   note: string|null,
     *   status: string,
     *   treatment_id: int|null,
     *   original_transaction_id: int|null,
     *   refundable_amount: string,
     * }>
     */
    public function transactionsForTreatment(int $treatmentId): array;
}
