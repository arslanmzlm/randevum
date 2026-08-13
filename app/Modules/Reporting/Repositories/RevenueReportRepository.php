<?php

namespace App\Modules\Reporting\Repositories;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read-only revenue aggregation over the transactions table. Reporting queries the
 * table directly instead of borrowing Billing's TransactionRepository — the module
 * boundary rule holds for repositories, and the reporting exception covers table
 * access only.
 */
class RevenueReportRepository
{
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
            ->excludingVoidedTreatments()
            ->sum('amount');

        return bcadd('0', (string) $total, 2);
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
            ->excludingVoidedTreatments()
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
            ->excludingVoidedTreatments()
            ->orderBy('paid_at')
            ->get(['paid_at', 'amount', 'payment_method', 'patient_id', 'category']);
    }
}
