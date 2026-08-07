<?php

namespace App\Modules\Billing\Services;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Billing\Repositories\TransactionRepository;
use App\Modules\Core\Services\StatusLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RefundService
{
    public function __construct(
        private TransactionRepository $repository,
        private StatusLogService $statusLogService,
        private InstallmentSettlementService $settlement,
    ) {}

    /**
     * Record a refund counter-entry for the given original transaction.
     *
     * Creates a negative-amount transaction (the counter-entry), updates the original's
     * status to PartiallyRefunded or Refunded, and logs both status transitions.
     * All work is wrapped in a DB transaction for atomicity.
     *
     * @param  array{amount: float|string, reason: string}  $data
     */
    public function refund(Transaction $original, array $data, User $actor): Transaction
    {
        return DB::transaction(function () use ($original, $data, $actor) {
            $refundAmount = (string) $data['amount'];
            $reason = $data['reason'];

            // Lock the original first, before deriving anything from it: two concurrent
            // refunds against the same payment must serialize here, so the second sees the
            // first's already-committed refund instead of both reading the same stale
            // remaining under Postgres READ COMMITTED.
            $locked = $this->repository->lockForUpdate($original->id);

            $refundedSoFar = $this->repository->refundedTotalFor($locked->id);
            $remaining = bcsub((string) $locked->amount, $refundedSoFar, 2);

            // Defense-in-depth: FormRequest already validates amount > 0 and ≤ original.amount;
            // service re-guards the remaining cap and refundability state.
            if (
                ! in_array($locked->status, [TransactionStatus::Completed, TransactionStatus::PartiallyRefunded], true)
                || bccomp((string) $locked->amount, '0', 2) <= 0
            ) {
                throw ValidationException::withMessages([
                    'amount' => __('transactions.errors.not_refundable'),
                ]);
            }

            if (bccomp($refundAmount, '0', 2) <= 0 || bccomp($refundAmount, $remaining, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => __('transactions.errors.amount_exceeds_remaining'),
                ]);
            }

            // Counter-entry: negative amount, status Refunded, linked to original. Carries the
            // installment link too, so a refunded collection's derived remaining stays correct.
            $counterEntry = $this->repository->create([
                'patient_id' => $locked->patient_id,
                'treatment_id' => $locked->treatment_id,
                'original_transaction_id' => $locked->id,
                'payment_plan_installment_id' => $locked->payment_plan_installment_id,
                'category' => $locked->category,
                'amount' => bcsub('0', $refundAmount, 2),
                'payment_method' => $locked->payment_method->value,
                'status' => TransactionStatus::Refunded->value,
                'note' => $reason,
                'paid_at' => now(),
                'created_by' => $actor->id,
            ]);

            $this->statusLogService->record(
                $counterEntry,
                null,
                TransactionStatus::Refunded->value,
                $actor,
                $reason,
            );

            // Determine original's new status.
            $totalRefunded = bcadd($refundedSoFar, $refundAmount, 2);
            $newStatus = bccomp($totalRefunded, (string) $locked->amount, 2) === 0
                ? TransactionStatus::Refunded
                : TransactionStatus::PartiallyRefunded;

            $fromStatus = $locked->status->value;
            $locked->status = $newStatus;
            $locked->save();

            $this->statusLogService->record(
                $locked,
                $fromStatus,
                $newStatus->value,
                $actor,
                $reason,
            );

            if ($locked->payment_plan_installment_id !== null) {
                $installment = $locked->installment()->first();

                if ($installment !== null) {
                    $this->settlement->sync($installment, $actor);
                }
            }

            return $counterEntry;
        });
    }

    /**
     * Remaining refundable for an original payment; 0.00 for counter-entries, fully-refunded
     * rows and non-positive amounts. $refundedSoFar is the POSITIVE cumulative refunded total.
     */
    public function refundableFor(Transaction $transaction, string $refundedSoFar): string
    {
        if (
            $transaction->original_transaction_id !== null
            || $transaction->status === TransactionStatus::Refunded
            || bccomp((string) $transaction->amount, '0', 2) <= 0
        ) {
            return '0.00';
        }

        $remaining = bcsub((string) $transaction->amount, $refundedSoFar, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }
}
