<?php

namespace App\Modules\Reporting\Repositories;

use App\Models\Expense;

/**
 * Read-only expense aggregation over the expenses table. Reporting queries the table
 * directly instead of borrowing Billing's ExpenseRepository — the module boundary rule
 * holds for repositories, and the reporting exception covers table access only.
 *
 * Every date-window filter below uses whereDate(), not a plain where() with a 'Y-m-d'
 * string: the date cast on write persists the full "Y-m-d 00:00:00" on drivers without
 * a native DATE type (e.g. SQLite), which breaks an inclusive upper-bound comparison
 * against a bare 'Y-m-d' string.
 */
class ExpenseReportRepository
{
    /**
     * Sum of expense amounts for the active clinic within the given expense_date
     * window (either bound may be omitted). Returns a 2-dp decimal string for
     * bcmath comparisons.
     */
    public function totalBetween(?string $startDate, ?string $endDate): string
    {
        $total = Expense::query()
            ->when($startDate, fn ($query) => $query->whereDate('expense_date', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate('expense_date', '<=', $endDate))
            ->sum('amount');

        // Normalize the DB decimal SUM to a 2-dp string via bcmath — never a (float)
        // round-trip: this string feeds bcsub for the net, and a float would drift cents.
        return bcadd('0', (string) $total, 2);
    }

    /**
     * Per-category totals for the active clinic within the given window, one grouped
     * SUM query. A null category groups under the '' array key (PHP casts a null
     * array key to '') — callers translate that back to null.
     *
     * @return array<string, string> category => total (decimal string)
     */
    public function byCategoryBetween(?string $startDate, ?string $endDate): array
    {
        return Expense::query()
            ->when($startDate, fn ($query) => $query->whereDate('expense_date', '>=', $startDate))
            ->when($endDate, fn ($query) => $query->whereDate('expense_date', '<=', $endDate))
            ->groupBy('category')
            ->selectRaw('category, sum(amount) as total')
            ->pluck('total', 'category')
            ->map(fn ($total): string => bcadd('0', (string) $total, 2))
            ->all();
    }
}
