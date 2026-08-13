<?php

namespace App\Modules\Billing\Repositories;

use App\Models\Expense;
use App\Support\FilterHelper;
use Illuminate\Pagination\LengthAwarePaginator;

class ExpenseRepository
{
    /**
     * The date-window filters below use whereDate(), not a plain where() with a
     * 'Y-m-d' string: the date cast on write persists the full "Y-m-d 00:00:00" on
     * drivers without a native DATE type (e.g. SQLite), which breaks an inclusive
     * upper-bound comparison against a bare 'Y-m-d' string.
     */

    /**
     * Paginated expense list for the active clinic (ClinicScope isolates the tenant),
     * date-range + category filtered. $ownerUserId narrows to that user's own rows
     * (Giderlerim's own-only list); null returns every clinic expense (finance page).
     *
     * @return LengthAwarePaginator<Expense>
     */
    public function paginateForActiveClinic(
        ?int $ownerUserId,
        ?string $startDate,
        ?string $endDate,
        ?string $category,
    ): LengthAwarePaginator {
        return FilterHelper::for(
            Expense::query()
                ->with('creator:id,first_name,last_name')
                ->when($ownerUserId, fn ($query) => $query->where('created_by', $ownerUserId))
                ->when($startDate, fn ($query) => $query->whereDate('expense_date', '>=', $startDate))
                ->when($endDate, fn ($query) => $query->whereDate('expense_date', '<=', $endDate))
                ->when($category, fn ($query) => $query->where('category', $category)),
        )
            ->sort('expense_date', 'amount', 'category', 'created_at')
            ->paginate();
    }

    /**
     * Distinct non-null category values for the active clinic, sorted — feeds the
     * category autocomplete. Mirrors ProductRepository::distinctValues.
     *
     * @return list<string>
     */
    public function distinctValues(string $column): array
    {
        return Expense::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }

    /** ClinicScope keeps this to the active clinic, so another clinic's id resolves to null. */
    public function find(int $id): ?Expense
    {
        return Expense::with('creator')->find($id);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Expense
    {
        return Expense::create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data): Expense
    {
        $expense->fill($data)->save();

        return $expense;
    }

    public function delete(Expense $expense): void
    {
        $expense->delete();
    }
}
