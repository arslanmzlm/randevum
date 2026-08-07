<?php

namespace App\Modules\Billing\Services;

use App\Enums\InstallmentStatus;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transaction;
use App\Modules\Billing\Contracts\BalanceReaderContract;
use App\Modules\Billing\Repositories\PaymentPlanRepository;
use App\Modules\Billing\Repositories\TransactionRepository;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;

class BalanceService implements BalanceReaderContract
{
    public function __construct(
        private TransactionRepository $repository,
        private PaymentPlanRepository $paymentPlanRepository,
        private ClinicContext $clinicContext,
        private InstallmentSettlementService $settlement,
        private RefundService $refundService,
    ) {}

    public function paidTotalForPatient(int $patientId): string
    {
        return $this->repository->paidTotalForPatient($patientId);
    }

    /**
     * {@inheritDoc}
     */
    public function paidTotalsForPatients(array $patientIds): array
    {
        return $this->repository->paidTotalsForPatients($patientIds);
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
     * {@inheritDoc}
     */
    public function paymentPlansForPatient(int $patientId): array
    {
        $today = Carbon::now($this->clinicContext->timezone())->toDateString();

        return $this->paymentPlanRepository->plansForPatient($patientId)
            ->map(fn (PaymentPlan $plan) => [
                'id' => $plan->id,
                'status' => $plan->status->value,
                'total_amount' => (string) $plan->total_amount,
                'down_payment' => $plan->down_payment !== null ? (string) $plan->down_payment : null,
                'installment_count' => $plan->installment_count,
                'treatment_id' => $plan->treatment_id,
                'created_at' => $plan->created_at->toIso8601String(),
                'installments' => $plan->installments
                    ->map(function (PaymentPlanInstallment $installment) use ($today) {
                        $collected = $this->settlement->collectedFromLoaded($installment);
                        $remaining = bcsub((string) $installment->amount, $collected, 2);
                        $remaining = bccomp($remaining, '0', 2) > 0 ? $remaining : '0.00';

                        return [
                            'id' => $installment->id,
                            'sequence' => $installment->sequence,
                            'due_date' => $installment->due_date->toDateString(),
                            'amount' => (string) $installment->amount,
                            'status' => $installment->status->value,
                            'paid_at' => $installment->paid_at?->toIso8601String(),
                            'collected_amount' => $collected,
                            'remaining_amount' => $remaining,
                            'is_overdue' => in_array($installment->status, [InstallmentStatus::Pending, InstallmentStatus::PartiallyPaid], true)
                                && $installment->due_date->toDateString() < $today,
                        ];
                    })
                    ->all(),
            ])
            ->all();
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
        return $this->refundService->refundableFor($tx, $refundedMap[$tx->id] ?? '0.00');
    }
}
