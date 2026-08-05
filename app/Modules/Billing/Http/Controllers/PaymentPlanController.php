<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use App\Models\Transaction;
use App\Modules\Billing\Http\Requests\CollectInstallmentRequest;
use App\Modules\Billing\Http\Requests\StorePaymentPlanRequest;
use App\Modules\Billing\Repositories\PaymentPlanRepository;
use App\Modules\Billing\Services\InstallmentReminderService;
use App\Modules\Billing\Services\PaymentPlanService;
use App\Modules\Core\Support\Toast;
use App\Support\ClinicContext;
use App\Support\FilterHelper;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PaymentPlanController extends Controller
{
    public function __construct(
        private PaymentPlanService $service,
        private PaymentPlanRepository $repository,
        private InstallmentReminderService $reminderService,
        private ClinicContext $clinicContext,
    ) {}

    /**
     * GET /payment-plans/installments
     * Pending-installments collections screen: all the clinic's due/overdue installments.
     */
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', PaymentPlan::class);

        $timezone = $this->clinicContext->timezone();
        $today = Carbon::now($timezone)->toDateString();
        $dueSoonEnd = Carbon::now($timezone)->addDays(7)->toDateString();

        $installments = $this->repository->pendingInstallmentsForClinic()
            ->map(function (PaymentPlanInstallment $installment) use ($today) {
                $plan = $installment->plan;

                return [
                    'id' => $installment->id,
                    'plan_id' => $plan->id,
                    'patient_id' => $plan->patient_id,
                    'patient_name' => trim("{$plan->patient->first_name} {$plan->patient->last_name}"),
                    'treatment_id' => $plan->treatment_id,
                    'sequence' => $installment->sequence,
                    // Row shows "3 / 6": a bare sequence number says nothing about how far along
                    // the plan is.
                    'installment_count' => $plan->installment_count,
                    'due_date' => $installment->due_date->toDateString(),
                    'amount' => (string) $installment->amount,
                    'status' => $installment->status->value,
                    'is_overdue' => $installment->status->value === 'pending' && $installment->due_date->toDateString() < $today,
                    'plan_total' => (string) $plan->total_amount,
                    'plan_status' => $plan->status->value,
                    'reminder_7d_sent' => $installment->reminder_7d_sent,
                    'reminder_1d_sent' => $installment->reminder_1d_sent,
                ];
            })
            ->values();

        return Inertia::render('payment-plans/Index', [
            'installments' => $installments,
            'stats' => $this->repository->pendingStatsForClinic($today, $dueSoonEnd),
            'query' => FilterHelper::requestState([
                'status' => 'string',
                'patient_id' => 'integer',
                'due_after' => 'date',
                'due_before' => 'date',
            ]),
        ]);
    }

    /**
     * POST /payment-plans
     * Standalone plan create — patient detail's "taksit planı oluştur".
     */
    public function store(StorePaymentPlanRequest $request): RedirectResponse
    {
        $this->authorize('create', PaymentPlan::class);

        $this->service->create($request->validated(), $request->user());

        Toast::success(__('messages.payment_plan.created'));

        return back();
    }

    /**
     * POST /payment-plans/installments/{installment}/collect
     */
    public function collect(CollectInstallmentRequest $request, PaymentPlanInstallment $installment): RedirectResponse
    {
        $this->authorize('create', Transaction::class);

        $this->service->collect($installment, $request->validated(), $request->user());

        Toast::success(__('messages.payment_plan.collected'));

        return back();
    }

    /**
     * POST /payment-plans/installments/{installment}/remind
     */
    public function sendReminder(Request $request, PaymentPlanInstallment $installment): RedirectResponse
    {
        $installment->loadMissing('plan');

        $this->authorize('sendReminder', $installment->plan);

        $dispatched = $this->reminderService->sendManual($installment);

        if ($dispatched) {
            Toast::success(__('messages.payment_plan.reminder_sent'));
        } else {
            Toast::warning(__('messages.payment_plan.reminder_skipped'));
        }

        return back();
    }

    /**
     * POST /payment-plans/{paymentPlan}/cancel
     */
    /**
     * DELETE /payment-plans/{paymentPlan} — for a plan entered by mistake. Blocked by the policy
     * once any installment has been collected; cancel that one instead.
     */
    public function destroy(PaymentPlan $paymentPlan): RedirectResponse
    {
        $this->authorize('delete', $paymentPlan);

        $this->service->delete($paymentPlan);

        Toast::success(__('messages.payment_plan.deleted'));

        return back();
    }

    public function cancel(Request $request, PaymentPlan $paymentPlan): RedirectResponse
    {
        $this->authorize('cancel', $paymentPlan);

        $this->service->cancel($paymentPlan, $request->user());

        Toast::success(__('messages.payment_plan.cancelled'));

        return back();
    }
}
