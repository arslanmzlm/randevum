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

            $refundedSoFar = $this->repository->refundedTotalFor($original->id);
            $remaining = bcsub((string) $original->amount, $refundedSoFar, 2);

            // Defense-in-depth: FormRequest already validates amount > 0 and ≤ original.amount;
            // service re-guards the remaining cap and refundability state.
            if (
                ! in_array($original->status, [TransactionStatus::Completed, TransactionStatus::PartiallyRefunded], true)
                || bccomp((string) $original->amount, '0', 2) <= 0
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

            // Counter-entry: negative amount, status Refunded, linked to original.
            $counterEntry = $this->repository->create([
                'patient_id' => $original->patient_id,
                'treatment_id' => $original->treatment_id,
                'original_transaction_id' => $original->id,
                'amount' => bcsub('0', $refundAmount, 2),
                'payment_method' => $original->payment_method->value,
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
            $newStatus = bccomp($totalRefunded, (string) $original->amount, 2) === 0
                ? TransactionStatus::Refunded
                : TransactionStatus::PartiallyRefunded;

            $fromStatus = $original->status->value;
            $original->status = $newStatus;
            $original->save();

            $this->statusLogService->record(
                $original,
                $fromStatus,
                $newStatus->value,
                $actor,
                $reason,
            );

            return $counterEntry;
        });
    }
}
