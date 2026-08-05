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
     * Collect a pending installment (v1: full-installment only, no partial). Records the
     * transaction via the existing PaymentRecorderContract (linked via
     * payment_plan_installment_id), flips the installment to Paid, and auto-completes the
     * plan once every installment is Paid.
     *
     * @param  array{payment_method: string, paid_at?: string|\DateTimeInterface|null, note?: string|null}  $data
     */
    public function collect(PaymentPlanInstallment $installment, array $data, User $actor): Transaction
    {
        if ($installment->status !== InstallmentStatus::Pending) {
            throw ValidationException::withMessages([
                'installment' => [__('payment_plan.errors.not_pending')],
            ]);
        }

        $installment->loadMissing('plan');
        $plan = $installment->plan;

        // Resolve once so a backdated collection shows the same date on the transaction
        // and the installment row (PaymentPlanCard reads installment.paid_at).
        $paidAt = $data['paid_at'] ?? now();

        return DB::transaction(function () use ($installment, $plan, $data, $actor, $paidAt): Transaction {
            $transaction = $this->paymentRecorder->record([
                'amount' => $installment->amount,
                'payment_method' => $data['payment_method'],
                'patient_id' => $plan->patient_id,
                'treatment_id' => $plan->treatment_id,
                'note' => $data['note'] ?? null,
                'paid_at' => $paidAt,
                'payment_plan_installment_id' => $installment->id,
            ], $actor);

            $installment->status = InstallmentStatus::Paid;
            $installment->paid_at = $paidAt;
            $installment->save();

            $this->statusLogService->record(
                $installment,
                InstallmentStatus::Pending->value,
                InstallmentStatus::Paid->value,
                $actor,
            );

            if ($this->repository->allPaid($plan->id)) {
                $fromStatus = $plan->status->value;
                $plan->status = PaymentPlanStatus::Completed;
                $plan->save();

                $this->statusLogService->record(
                    $plan,
                    $fromStatus,
                    PaymentPlanStatus::Completed->value,
                    $actor,
                );
            }

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

            $pendingInstallments = $plan->installments()
                ->where('status', InstallmentStatus::Pending->value)
                ->get();

            foreach ($pendingInstallments as $installment) {
                $installment->status = InstallmentStatus::Cancelled;
                $installment->save();

                $this->statusLogService->record(
                    $installment,
                    InstallmentStatus::Pending->value,
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
