<?php

namespace App\Modules\Billing\Services;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Billing\Repositories\ManualIncomeRepository;
use App\Modules\Core\Events\ClinicFinancesChanged;
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
        private RefundService $refundService,
    ) {}

    /**
     * @return LengthAwarePaginator<Transaction>
     */
    public function paginate(?int $ownerUserId, ?string $startDate, ?string $endDate, ?string $category, string $timezone): LengthAwarePaginator
    {
        $paginator = $this->repository->paginateForActiveClinic($ownerUserId, $startDate, $endDate, $category, $timezone);

        // refunded_total is a SUM of negative counter-entries → negate to the positive refunded
        // figure that refundableFor() expects (single definition, shared with BalanceService).
        $paginator->through(function (Transaction $transaction): Transaction {
            $refundedSoFar = bcsub('0', bcadd('0', (string) ($transaction->refunded_total ?? '0'), 2), 2);
            $transaction->setAttribute('refundable_amount', $this->refundService->refundableFor($transaction, $refundedSoFar));

            return $transaction;
        });

        return $paginator;
    }

    /**
     * @return array{categories: list<string>}
     */
    public function suggestions(?int $ownerUserId): array
    {
        return ['categories' => $this->repository->distinctCategories($ownerUserId)];
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
                'transaction' => [__('transactions.errors.not_manual_income')],
            ]);
        }

        // Refunded originals and counter-entries are never hard-deletable: deleting either side
        // corrupts money (orphaned counter-entry, or a resurrected refundable balance).
        if ($transaction->original_transaction_id !== null
            || $transaction->status !== TransactionStatus::Completed
            || $transaction->refunds()->exists()) {
            throw ValidationException::withMessages([
                'transaction' => [__('transactions.errors.delete_window_passed')],
            ]);
        }

        if ($transaction->created_at->diffInSeconds(now()) > config('platform.edit_windows.transaction_delete')) {
            throw ValidationException::withMessages([
                'transaction' => [__('transactions.errors.delete_window_passed')],
            ]);
        }

        $clinicId = $transaction->clinic_id;

        DB::transaction(function () use ($transaction): void {
            $transaction->statusLogs()->delete();
            $this->repository->delete($transaction);
        });

        ClinicFinancesChanged::dispatchAfterCommit($clinicId);
    }
}
