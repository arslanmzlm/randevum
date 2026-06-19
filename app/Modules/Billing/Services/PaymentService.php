<?php

namespace App\Modules\Billing\Services;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Billing\Repositories\TransactionRepository;
use App\Modules\Core\Services\StatusLogService;
use App\Modules\Medical\Contracts\TreatmentReaderContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService implements PaymentRecorderContract
{
    public function __construct(
        private TransactionRepository $repository,
        private StatusLogService $statusLogService,
        private TreatmentReaderContract $treatmentReader,
    ) {}

    /**
     * Create a Completed transaction and log the null → completed transition.
     *
     * Overpayment guard: when the linked treatment is Completed, the existing
     * paid total + the new amount must not exceed the treatment's total_amount.
     * Draft treatments (prepayments) are not capped — the total is not final yet.
     *
     * @param  array{
     *     amount: float|string,
     *     payment_method: string,
     *     patient_id: int,
     *     treatment_id: int|null,
     *     note?: string|null,
     *     paid_at?: string|\DateTimeInterface|null,
     * }  $data
     */
    public function record(array $data, User $actor): Transaction
    {
        $treatmentId = $data['treatment_id'] ?? null;

        if ($treatmentId !== null) {
            $cap = $this->treatmentReader->completedTotalCap($treatmentId);

            if ($cap !== null) {
                $existingPaid = $this->repository->paidTotalForTreatment($treatmentId);

                if (bccomp(bcadd($existingPaid, (string) $data['amount'], 2), $cap, 2) > 0) {
                    throw ValidationException::withMessages([
                        'amount' => __('treatment.errors.payments_exceed_total'),
                    ]);
                }
            }
        }

        return DB::transaction(function () use ($data, $treatmentId, $actor) {
            $transaction = $this->repository->create([
                'patient_id' => $data['patient_id'],
                'treatment_id' => $treatmentId,
                'amount' => $data['amount'],
                'payment_method' => $data['payment_method'],
                'status' => TransactionStatus::Completed->value,
                'paid_at' => $data['paid_at'] ?? now(),
                'note' => $data['note'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->statusLogService->record(
                $transaction,
                null,
                TransactionStatus::Completed->value,
                $actor,
            );

            return $transaction;
        });
    }
}
