<?php

namespace App\Modules\Billing\Repositories;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Carbon\CarbonInterface;
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
        $total = Transaction::where('treatment_id', $treatmentId)->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Sum of all transaction amounts for a patient in the active clinic.
     * BelongsToClinic global scope provides tenant isolation automatically.
     * Filtered on patient_id, so a manual-income row (patient_id NULL) never counts here.
     */
    public function paidTotalForPatient(int $patientId): string
    {
        $total = Transaction::where('patient_id', $patientId)->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Sum of all transaction amounts per patient, keyed by patient_id — the "paid" side of the
     * derived balance, in one grouped query. Active-clinic scoped (ClinicScope); only the given
     * patients are queried.
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
            ->groupBy('patient_id')
            ->selectRaw('patient_id, sum(amount) as total')
            ->pluck('total', 'patient_id')
            ->map(fn ($total): string => (string) $total)
            ->all();
    }

    /**
     * Total amount already refunded against a given original transaction.
     * Counter-entries carry negative amounts; this returns the positive cumulative total refunded.
     * Returns a 2-dp decimal string suitable for bcmath comparisons.
     */
    public function refundedTotalFor(int $originalId): string
    {
        $total = number_format((float) Transaction::where('original_transaction_id', $originalId)->sum('amount'), 2, '.', '');

        // SUM of negative amounts is ≤ 0; negate to get the positive refunded figure.
        return bcsub('0', $total, 2);
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
     * report via collectedBetween()/settledRowsBetween()/allSettledRows().
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
            ->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Net collected for the active clinic with paid_at in the given UTC range —
     * non-pending only, refund counter-entries (negative amounts) included so the
     * result is net-of-refunds. A cheap SUM for the report's summary cards.
     *
     * BelongsToClinic global scope provides tenant isolation automatically.
     * Returns a 2-dp decimal string (e.g. "1250.00").
     */
    public function collectedBetween(CarbonInterface $startUtc, CarbonInterface $endUtc): string
    {
        $total = Transaction::whereBetween('paid_at', [$startUtc, $endUtc])
            ->whereNot('status', TransactionStatus::Pending)
            ->sum('amount');

        return number_format((float) $total, 2, '.', '');
    }

    /**
     * Settled (non-pending) rows for the active clinic with paid_at in the given UTC
     * range, carrying only the columns revenue aggregation needs. Negative refund
     * counter-entries are included so callers net them. Day/method bucketing is done
     * in PHP against the clinic timezone (DB-agnostic: sqlite tests + pgsql prod), so
     * rows are returned rather than grouped in SQL. patient_id/category let the report
     * split patient collections from manual income (patient_id NULL).
     *
     * BelongsToClinic global scope provides tenant isolation automatically.
     *
     * @return Collection<int, Transaction>
     */
    public function settledRowsBetween(CarbonInterface $startUtc, CarbonInterface $endUtc): Collection
    {
        return Transaction::whereBetween('paid_at', [$startUtc, $endUtc])
            ->whereNot('status', TransactionStatus::Pending)
            ->orderBy('paid_at')
            ->get(['paid_at', 'amount', 'payment_method', 'patient_id', 'category']);
    }

    /**
     * Every settled (non-pending) row for the active clinic, oldest first — the all-time
     * revenue feed. Same slim column set as {@see settledRowsBetween()}; the report buckets
     * these in PHP (monthly past ~3 months, so the table never explodes). Guarded by the
     * report's 1-hour cache so a full-history scan runs at most once an hour per clinic.
     *
     * BelongsToClinic global scope provides tenant isolation automatically.
     *
     * @return Collection<int, Transaction>
     */
    public function allSettledRows(): Collection
    {
        return Transaction::whereNot('status', TransactionStatus::Pending)
            ->orderBy('paid_at')
            ->get(['paid_at', 'amount', 'payment_method', 'patient_id', 'category']);
    }
}
