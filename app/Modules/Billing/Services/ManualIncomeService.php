<?php

namespace App\Modules\Billing\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Billing\Repositories\ManualIncomeRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Manuel gelir — clinic income with no patient, written to transactions with patient_id NULL
 * (owner brief: no new table). Reuses PaymentRecorderContract so there is exactly one
 * transaction-creation path (status log included).
 */
class ManualIncomeService
{
    public function __construct(
        private ManualIncomeRepository $repository,
        private PaymentRecorderContract $paymentRecorder,
    ) {}

    /**
     * @return LengthAwarePaginator<Transaction>
     */
    public function paginate(?string $startDate, ?string $endDate, ?string $category, string $timezone): LengthAwarePaginator
    {
        return $this->repository->paginateForActiveClinic($startDate, $endDate, $category, $timezone);
    }

    /**
     * @return array{categories: list<string>}
     */
    public function suggestions(): array
    {
        return ['categories' => $this->repository->distinctCategories()];
    }

    /** The active clinic's manual income row by id, or null. */
    public function findForActiveClinic(?int $id): ?Transaction
    {
        return $id === null || $id <= 0
            ? null
            : $this->repository->find($id);
    }

    /**
     * @param  array{paid_at: string, amount: float|string, payment_method: string, category?: string|null, note?: string|null}  $data
     */
    public function create(array $data, User $actor): Transaction
    {
        return $this->paymentRecorder->record([
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'patient_id' => null,
            'treatment_id' => null,
            'note' => $data['note'] ?? null,
            'paid_at' => $data['paid_at'],
            'category' => $data['category'] ?? null,
        ], $actor);
    }

    /**
     * Hard-deletes a manual income row within the immutability window (transactions are
     * otherwise immutable — deletion-retention). Re-guards patient_id null even though the
     * repository/policy already narrow to it, in case this is ever called from elsewhere.
     */
    public function delete(Transaction $transaction): void
    {
        if ($transaction->patient_id !== null) {
            throw ValidationException::withMessages([
                'transaction' => [__('transactions.errors.delete_window_passed')],
            ]);
        }

        if ($transaction->created_at->diffInSeconds(now()) > config('platform.edit_windows.transaction_delete')) {
            throw ValidationException::withMessages([
                'transaction' => [__('transactions.errors.delete_window_passed')],
            ]);
        }

        DB::transaction(function () use ($transaction): void {
            $transaction->statusLogs()->delete();
            $this->repository->delete($transaction);
        });
    }
}
