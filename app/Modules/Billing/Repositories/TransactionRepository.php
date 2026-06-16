<?php

namespace App\Modules\Billing\Repositories;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Collection;

class TransactionRepository
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Transaction
    {
        return Transaction::create($data);
    }

    /**
     * Sum of all transaction amounts for a treatment (the derived "paid" balance).
     * Returns a string to preserve decimal precision for bcmath comparisons.
     */
    public function paidTotalForTreatment(int $treatmentId): string
    {
        return (string) Transaction::where('treatment_id', $treatmentId)->sum('amount');
    }

    /**
     * Sum of all transaction amounts for a patient in the active clinic.
     * BelongsToClinic global scope provides tenant isolation automatically.
     */
    public function paidTotalForPatient(int $patientId): string
    {
        return (string) Transaction::where('patient_id', $patientId)->sum('amount');
    }

    /**
     * Total amount already refunded against a given original transaction.
     * Counter-entries carry negative amounts; this returns the positive cumulative total refunded.
     * Returns a 2-dp decimal string suitable for bcmath comparisons.
     */
    public function refundedTotalFor(int $originalId): string
    {
        $total = (string) Transaction::where('original_transaction_id', $originalId)->sum('amount');

        // SUM of negative amounts is ≤ 0; negate to get the positive refunded figure.
        return bcsub('0', $total ?: '0', 2);
    }

    /**
     * All transactions for a patient in the active clinic, newest first.
     * BelongsToClinic global scope provides tenant isolation automatically.
     *
     * @return Collection<int, Transaction>
     */
    public function forPatient(int $patientId): Collection
    {
        return Transaction::where('patient_id', $patientId)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * All transactions for a treatment in the active clinic, newest first.
     * BelongsToClinic global scope provides tenant isolation automatically.
     *
     * @return Collection<int, Transaction>
     */
    public function forTreatment(int $treatmentId): Collection
    {
        return Transaction::where('treatment_id', $treatmentId)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Net collected amount for the active clinic within the current calendar day in
     * the given timezone. SUM includes negative refund counter-entries so the result
     * is net-of-refunds. Pending transactions are excluded — paid_at is NOT NULL on
     * every row, so a date filter alone is insufficient; only settled rows count.
     *
     * BelongsToClinic global scope provides tenant isolation automatically.
     * Returns a 2-dp decimal string (e.g. "1250.00").
     */
    public function collectedTodayTotal(string $timezone): string
    {
        $dayStart = now($timezone)->startOfDay()->utc();
        $dayEnd = now($timezone)->endOfDay()->utc();

        $total = Transaction::whereBetween('paid_at', [$dayStart, $dayEnd])
            ->whereNot('status', TransactionStatus::Pending)
            ->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }
}
