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
     * Lock the transaction row for the rest of the caller's transaction (see
     * RefundService::refund), so two concurrent refunds against the same original cannot
     * both read the same stale refunded total and both pass the remaining-amount guard.
     */
    public function lockForUpdate(int $id): Transaction
    {
        return Transaction::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /**
     * Sum of all settled transaction amounts for a treatment (the derived "paid" balance).
     * Pending is excluded, same as the other "paid"/collected totals below — it hasn't
     * settled yet, so it must not count toward the payment-cap check that reads this.
     * Returns a string to preserve decimal precision for bcmath comparisons.
     */
    public function paidTotalForTreatment(int $treatmentId): string
    {
        $total = Transaction::where('treatment_id', $treatmentId)
            ->whereNot('status', TransactionStatus::Pending)
            ->sum('amount');

        // Normalize the DB decimal SUM to a 2-dp string via bcmath — never a (float)
        // round-trip: this string feeds bccomp/bcadd downstream, and a float would drift cents.
        return bcadd('0', (string) $total, 2);
    }

    /**
     * Sum of all settled transaction amounts for a patient in the active clinic. Pending
     * is excluded (see paidTotalForTreatment). BelongsToClinic global scope provides tenant
     * isolation automatically. Filtered on patient_id, so a manual-income row (patient_id
     * NULL) never counts here.
     */
    public function paidTotalForPatient(int $patientId): string
    {
        $total = Transaction::where('patient_id', $patientId)
            ->whereNot('status', TransactionStatus::Pending)
            ->sum('amount');

        return bcadd('0', (string) $total, 2);
    }

    /**
     * Sum of all settled transaction amounts per patient, keyed by patient_id — the "paid" side
     * of the derived balance, in one grouped query. Pending is excluded (see
     * paidTotalForTreatment). Active-clinic scoped (ClinicScope); only the given patients are
     * queried.
     *
     * @param  array<int, int>  $patientIds
     * @return array<int, string> patient_id => paid total (decimal string)
     */
    public function paidTotalsForPatients(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        return Transaction::query()
            ->whereIn('patient_id', $patientIds)
            ->whereNot('status', TransactionStatus::Pending)
            ->groupBy('patient_id')
            ->selectRaw('patient_id, sum(amount) as total')
            ->pluck('total', 'patient_id')
            ->map(fn ($total): string => bcadd('0', (string) $total, 2))
            ->all();
    }

    /**
     * Total amount already refunded against a given original transaction.
     * Counter-entries carry negative amounts; this returns the positive cumulative total refunded.
     * Returns a 2-dp decimal string suitable for bcmath comparisons.
     */
    public function refundedTotalFor(int $originalId): string
    {
        $total = Transaction::where('original_transaction_id', $originalId)->sum('amount');

        // SUM of negative amounts is ≤ 0; negate to get the positive refunded figure.
        return bcsub('0', (string) $total, 2);
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
     * Patient collections only (dashboard tile) — manual income (patient_id NULL) stays
     * out so it doesn't inflate "bugün tahsil edilen"; it still counts in the finance
     * report, which aggregates the settled rows on its own (Reporting module).
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
            ->whereNotNull('patient_id')
            ->excludingVoidedTreatments()
            ->sum('amount');

        return bcadd('0', (string) $total, 2);
    }
}
