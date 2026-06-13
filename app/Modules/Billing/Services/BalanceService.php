<?php

namespace App\Modules\Billing\Services;

use App\Models\Transaction;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Billing\Repositories\TransactionRepository;

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
     * }>
     */
    public function transactionsForPatient(int $patientId): array
    {
        return $this->repository->forPatient($patientId)
            ->map(fn (Transaction $tx) => [
                'id' => $tx->id,
                'paid_at' => $tx->paid_at->toIso8601String(),
                'payment_method' => $tx->payment_method->value,
                'amount' => (string) $tx->amount,
                'note' => $tx->note,
                'status' => $tx->status->value,
                'treatment_id' => $tx->treatment_id,
            ])
            ->all();
    }
}
