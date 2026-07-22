<?php

namespace App\Modules\Billing\Services;

use App\Models\Expense;
use App\Modules\Billing\Repositories\ExpenseRepository;
use Illuminate\Pagination\LengthAwarePaginator;

class ExpenseService
{
    public function __construct(
        private ExpenseRepository $repository,
    ) {}

    /**
     * Own-only list — Giderlerim, reachable via the create permission alone (no
     * dedicated viewOwn permission; see ExpensePolicy).
     *
     * @return LengthAwarePaginator<Expense>
     */
    public function paginateOwn(int $userId, ?string $startDate, ?string $endDate, ?string $category): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic($userId, $startDate, $endDate, $category);
    }

    /**
     * All-clinic list — the finance page, gated by expenses.viewAny.
     *
     * @return LengthAwarePaginator<Expense>
     */
    public function paginateClinic(?string $startDate, ?string $endDate, ?string $category): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic(null, $startDate, $endDate, $category);
    }

    /**
     * Existing category values for this clinic — the AutoComplete suggestions (no
     * separate category table; the clinic reuses what it typed before).
     *
     * @return array{categories: list<string>}
     */
    public function suggestions(): array
    {
        return ['categories' => $this->repository->distinctValues('category')];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, int $creatorId): Expense
    {
        $data['created_by'] = $creatorId;

        // clinic_id is auto-set by BelongsToClinic on create — not set manually.
        return $this->repository->create($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Expense $expense, array $data): Expense
    {
        return $this->repository->update($expense, $data);
    }

    public function delete(Expense $expense): void
    {
        $this->repository->delete($expense);
    }
}
