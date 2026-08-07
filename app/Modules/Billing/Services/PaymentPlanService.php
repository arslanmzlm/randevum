<?php

namespace App\Modules\Billing\Services;

use App\Enums\InstallmentStatus;
use App\Enums\PaymentPlanStatus;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transaction;
use App\Models\User;
use App\Modules\Billing\Contracts\PaymentPlanCreatorContract;
use App\Modules\Billing\Contracts\PaymentRecorderContract;
use App\Modules\Billing\Repositories\PaymentPlanRepository;
use App\Modules\Core\Services\StatusLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentPlanService implements PaymentPlanCreatorContract
{
    public function __construct(
        private PaymentPlanRepository $repository,
        private PaymentRecorderContract $paymentRecorder,
        private StatusLogService $statusLogService,
        private InstallmentSettlementService $settlement,
    ) {}

    /**
     * {@inheritDoc}
     */
    public function create(array $data, User $actor): void
    {
        $this->assertSumMatches($data);

        DB::transaction(function () use ($data, $actor): void {
            $plan = $this->repository->createPlan([
                'patient_id' => $data['patient_id'],
                'treatment_id' => $data['treatment_id'] ?? null,
                'total_amount' => $data['total_amount'],
                'down_payment' => $data['down_payment'] ?? null,
                'installment_count' => $data['installment_count'],
                'status' => PaymentPlanStatus::Active->value,
                'created_by' => $actor->id,
            ]);

            $this->statusLogService->record($plan, null, PaymentPlanStatus::Active->value, $actor);

            $this->repository->insertInstallments($plan, $data['installments']);

            $downPayment = (string) ($data['down_payment'] ?? '0');

            // Down payment is collected immediately and is NOT linked to any installment
            // (Owner brief); the remaining N installments are what gets tracked/collected.
            if (bccomp($downPayment, '0', 2) > 0) {
                $this->paymentRecorder->record([
                    'amount' => $downPayment,
                    'payment_method' => $data['down_payment_method'],
                    'patient_id' => $data['patient_id'],
                    'treatment_id' => $data['treatment_id'] ?? null,
                    'note' => __('payment_plan.down_payment_note'),
                ], $actor);
            }
        });
    }

    /**
     * Collect a Pending or PartiallyPaid installment, in full or in part. Records the
     * transaction via the existing PaymentRecorderContract (linked via
     * payment_plan_installment_id), then re-derives the installment's (and plan's) status
     * from its collected total — never stored (payments-stock: always derive it).
     *
     * @param  array{payment_method: string, amount?: float|string|null, paid_at?: string|\DateTimeInterface|null, note?: string|null}  $data
     */
    public function collect(PaymentPlanInstallment $installment, array $data, User $actor): Transaction
    {
        // Resolve once so a backdated collection shows the same date on the transaction
        // and the installment row (PaymentPlanCard reads installment.paid_at).
        $paidAt = $data['paid_at'] ?? now();

        return DB::transaction(function () use ($installment, $data, $actor, $paidAt): Transaction {
            // Lock + re-read before deriving the remaining: two concurrent requests on the
            // same installment must serialize here, so the second sees the first's already-
            // committed collection instead of both reading the same stale remaining.
            $locked = $this->repository->lockInstallmentForUpdate($installment->id);
            $locked->loadMissing('plan');
            $plan = $locked->plan;

            if (! in_array($locked->status, [InstallmentStatus::Pending, InstallmentStatus::PartiallyPaid], true)) {
                throw ValidationException::withMessages([
                    'installment' => [__('payment_plan.errors.not_pending')],
                ]);
            }

            $remaining = $this->settlement->remaining($locked);
            $amount = (string) ($data['amount'] ?? $remaining);

            if (bccomp($amount, '0', 2) <= 0 || bccomp($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => [__('payment_plan.errors.amount_exceeds_remaining')],
                ]);
            }

            $transaction = $this->paymentRecorder->record([
                'amount' => $amount,
                'payment_method' => $data['payment_method'],
                'patient_id' => $plan->patient_id,
                'treatment_id' => $plan->treatment_id,
                'note' => $data['note'] ?? null,
                'paid_at' => $paidAt,
                'payment_plan_installment_id' => $locked->id,
            ], $actor);

            $this->settlement->sync($locked, $actor, $paidAt);

            return $transaction;
        });
    }

    /**
     * Cancel a plan: plan → Cancelled, pending installments → Cancelled. Does NOT reverse
     * already-collected transactions — a refund is a separate counter-entry (Owner brief).
     */
    public function cancel(PaymentPlan $plan, User $actor): void
    {
        DB::transaction(function () use ($plan, $actor): void {
            $fromStatus = $plan->status->value;
            $plan->status = PaymentPlanStatus::Cancelled;
            $plan->save();

            $this->statusLogService->record($plan, $fromStatus, PaymentPlanStatus::Cancelled->value, $actor);

            $openInstallments = $plan->installments()
                ->whereIn('status', [InstallmentStatus::Pending->value, InstallmentStatus::PartiallyPaid->value])
                ->get();

            foreach ($openInstallments as $installment) {
                $fromStatus = $installment->status->value;
                $installment->status = InstallmentStatus::Cancelled;
                $installment->save();

                $this->statusLogService->record(
                    $installment,
                    $fromStatus,
                    InstallmentStatus::Cancelled->value,
                    $actor,
                );
            }
        });
    }

    /**
     * bccomp sum-check, tolerance 0.00: Σ installment amounts + down_payment == total_amount;
     * sequences must be a contiguous 1..N covering installment_count.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    private function assertSumMatches(array $data): void
    {
        $installments = $data['installments'] ?? [];
        $sum = '0.00';
        $sequences = [];

        foreach ($installments as $row) {
            $sum = bcadd($sum, (string) $row['amount'], 2);
            $sequences[] = (int) $row['sequence'];
        }

        $downPayment = (string) ($data['down_payment'] ?? '0');
        $total = (string) $data['total_amount'];

        if (bccomp(bcadd($sum, $downPayment, 2), $total, 2) !== 0) {
            throw ValidationException::withMessages([
                'installments' => [__('payment_plan.errors.sum_mismatch')],
            ]);
        }

        sort($sequences);
        $expected = range(1, count($installments));

        if ($sequences !== $expected || count($installments) !== (int) $data['installment_count']) {
            throw ValidationException::withMessages([
                'installments' => [__('payment_plan.errors.sequence_invalid')],
            ]);
        }
    }

    /**
     * Remove a plan created by mistake, along with its (uncollected) installments and status
     * logs. The policy already refuses once anything has been collected; this re-checks so the
     * rule holds even if the service is called from somewhere else.
     */
    public function delete(PaymentPlan $plan): void
    {
        if ($plan->hasCollectedInstallments()) {
            throw ValidationException::withMessages([
                'plan' => __('payment_plan.errors.delete_after_collection'),
            ]);
        }

        DB::transaction(function () use ($plan): void {
            foreach ($plan->installments as $installment) {
                $installment->statusLogs()->delete();
                $installment->delete();
            }

            $plan->statusLogs()->delete();
            $plan->delete();
        });
    }
}
