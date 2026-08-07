<?php

namespace App\Modules\Billing\Repositories;

use App\Models\Transaction;
use App\Support\FilterHelper;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Manual income (clinic income with no patient — patient_id NULL) read/write. All queries are
 * additionally scoped to whereNull('patient_id') so a patient payment can never be reached
 * through the manual-income routes; ClinicScope on Transaction supplies tenant isolation.
 */
class ManualIncomeRepository
{
    /**
     * paid_at is a timestamptz (unlike expenses.expense_date, a plain DATE), so the clinic-local
     * window is converted to UTC and compared with a range rather than whereDate(). $ownerUserId
     * narrows to that user's own rows (a viewer without reports.revenue — see
     * ManualIncomeController::index); null returns every clinic manual-income row.
     *
     * @return LengthAwarePaginator<Transaction>
     */
    public function paginateForActiveClinic(
        ?int $ownerUserId,
        ?string $startDate,
        ?string $endDate,
        ?string $category,
        string $timezone,
    ): LengthAwarePaginator {
        return FilterHelper::for(
            Transaction::query()
                ->whereNull('patient_id')
                ->with('creator:id,first_name,last_name')
                ->withSum('refunds as refunded_total', 'amount')
                ->when($ownerUserId !== null, fn ($query) => $query->where('created_by', $ownerUserId))
                ->when(
                    $startDate,
                    fn ($query) => $query->where('paid_at', '>=', CarbonImmutable::parse($startDate, $timezone)->startOfDay()->utc()),
                )
                ->when(
                    $endDate,
                    fn ($query) => $query->where('paid_at', '<=', CarbonImmutable::parse($endDate, $timezone)->endOfDay()->utc()),
                )
                ->when($category, fn ($query) => $query->where('category', $category)),
        )
            ->sort('paid_at', 'amount', 'category', 'created_at')
            ->paginate();
    }

    /**
     * @return list<string>
     */
    public function distinctCategories(?int $ownerUserId): array
    {
        return Transaction::query()
            ->whereNull('patient_id')
            ->whereNotNull('category')
            ->when($ownerUserId !== null, fn ($query) => $query->where('created_by', $ownerUserId))
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();
    }

    public function delete(Transaction $transaction): void
    {
        $transaction->delete();
    }
}
