<?php

namespace App\Modules\Billing\Services;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Billing\Repositories\TransactionRepository;
use App\Modules\Core\Services\StatusLogService;

class PaymentService implements PaymentRecorderContract
{
    public function __construct(
        private TransactionRepository $repository,
        private StatusLogService $statusLogService,
    ) {}

    /**
     * Create a Completed transaction and log the null → completed transition.
     *
     * @param  array{
     *     amount: float|string,
     *     payment_method: string,
     *     patient_id: int,
     *     treatment_id: int|null,
     * }  $data
     */
    public function record(array $data, User $actor): Transaction
    {
        $transaction = $this->repository->create([
            'patient_id' => $data['patient_id'],
            'treatment_id' => $data['treatment_id'] ?? null,
            'amount' => $data['amount'],
            'payment_method' => $data['payment_method'],
            'status' => TransactionStatus::Completed->value,
            'paid_at' => now(),
            'created_by' => $actor->id,
        ]);

        $this->statusLogService->record(
            $transaction,
            null,
            TransactionStatus::Completed->value,
            $actor,
        );

        return $transaction;
    }
}
