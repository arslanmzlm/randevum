<?php

namespace App\Modules\Billing\Services;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Billing\Repositories\TransactionRepository;
use Illuminate\Database\Eloquent\Collection;

class BalanceService implements BalanceReaderContract
{
    public function __construct(
        private TransactionRepository $repository,
    ) {}

    public function paidTotalForPatient(int $patientId): string
    {
        return $this->repository->paidTotalForPatient($patientId);
    }

    /**
     * @return array<int, array{
     *   id: int,
     *   paid_at: string,
     *   payment_method: string,
     *   amount: string,
     *   note: string|null,
     *   status: string,
     *   treatment_id: int|null,
     *   original_transaction_id: int|null,
     *   refundable_amount: string,
     * }>
     */
    public function transactionsForPatient(int $patientId): array
    {
        return $this->serialize($this->repository->forPatient($patientId));
    }

    public function transactionsForTreatment(int $treatmentId): array
    {
        return $this->serialize($this->repository->forTreatment($treatmentId));
    }

    /**
     * Map a transaction collection to the plain display shape, computing each row's
     * refundable remaining in-memory (no extra query).
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return array<int, array{
     *   id: int,
     *   paid_at: string,
     *   payment_method: string,
     *   amount: string,
     *   note: string|null,
     *   status: string,
     *   treatment_id: int|null,
     *   original_transaction_id: int|null,
     *   refundable_amount: string,
     * }>
     */
    private function serialize(Collection $transactions): array
    {
        // Build: original_transaction_id → positive total refunded.
        $refundedMap = [];
        foreach ($transactions as $tx) {
            if ($tx->original_transaction_id !== null) {
                $key = $tx->original_transaction_id;
                $refundedMap[$key] = bcadd(
                    $refundedMap[$key] ?? '0.00',
                    bcsub('0', (string) $tx->amount, 2), // negate the negative amount
                    2
                );
            }
        }

        return $transactions
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'paid_at' => $tx->paid_at->toIso8601String(),
                'payment_method' => $tx->payment_method->value,
                'amount' => (string) $tx->amount,
                'note' => $tx->note,
                'status' => $tx->status->value,
                'treatment_id' => $tx->treatment_id,
                'original_transaction_id' => $tx->original_transaction_id,
                'refundable_amount' => $this->computeRefundableAmount($tx, $refundedMap),
            ])
            ->all();
    }

    /**
     * @param  array<int, string>  $refundedMap  original_id → positive total refunded
     */
    private function computeRefundableAmount(Transaction $tx, array $refundedMap): string
    {
        // Counter-entries, fully-refunded rows, and non-positive amounts are not refundable.
        if (
            $tx->original_transaction_id !== null
            || $tx->status === TransactionStatus::Refunded
            || bccomp((string) $tx->amount, '0', 2) <= 0
        ) {
            return '0.00';
        }

        $refundedSoFar = $refundedMap[$tx->id] ?? '0.00';
        $remaining = bcsub((string) $tx->amount, $refundedSoFar, 2);

        return bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';
    }
}
