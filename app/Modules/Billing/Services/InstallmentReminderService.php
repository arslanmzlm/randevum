<?php

namespace App\Modules\Billing\Services;

use App\Enums\SmsType;
use App\Models\PaymentPlanInstallment;
use App\Modules\Billing\Repositories\PaymentPlanRepository;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Contracts\SmsTemplateRendererContract;
use App\Modules\Messaging\Data\SmsMessage;
use Carbon\CarbonInterface;

class InstallmentReminderService
{
    private const LOGGABLE_TYPE = 'payment_plan_installment';

    public function __construct(
        private PaymentPlanRepository $repository,
        private SmsDispatcherContract $dispatcher,
        private SmsTemplateRendererContract $renderer,
    ) {}

    /**
     * Dispatch the 7-day and 1-day installment reminder waves for the given "now" moment.
     * Installments are date-granular (a DATE column), so this is a same-day match rather
     * than the appointment reminder's ±5min window. Returns the total dispatch calls made.
     */
    public function sendDueReminders(CarbonInterface $now): int
    {
        $dispatched = 0;

        $dispatched += $this->processWave(
            $now->copy()->addDays(7)->toDateString(),
            'reminder_7d_sent',
            SmsType::InstallmentDue7d,
        );

        $dispatched += $this->processWave(
            $now->copy()->addDay()->toDateString(),
            'reminder_1d_sent',
            SmsType::InstallmentDue1d,
        );

        return $dispatched;
    }

    /**
     * Manual "Hatırlat" triggered by staff for a single installment. Uses InstallmentDue1d
     * as the canonical type. Does NOT read or set the reminder_*_sent flags — staff may
     * deliberately resend regardless of the automatic wave's state.
     */
    public function sendManual(PaymentPlanInstallment $installment): void
    {
        $installment->loadMissing('plan.patient', 'plan.clinic');

        $message = $this->buildMessage($installment, SmsType::InstallmentDue1d);

        $this->dispatcher->dispatch($message);
    }

    private function processWave(string $onDate, string $flagColumn, SmsType $type): int
    {
        $installments = $this->repository->dueInstallments($onDate, $flagColumn);

        $count = 0;

        foreach ($installments as $installment) {
            // Second idempotency guard: a Sent log already exists for this installment+type.
            if ($this->dispatcher->wasSent(self::LOGGABLE_TYPE, $installment->id, $type)) {
                $this->repository->markReminderSent($installment, $flagColumn);

                continue;
            }

            $message = $this->buildMessage($installment, $type);

            $this->dispatcher->dispatch($message);

            $this->repository->markReminderSent($installment, $flagColumn);

            $count++;
        }

        return $count;
    }

    private function buildMessage(PaymentPlanInstallment $installment, SmsType $type): SmsMessage
    {
        $plan = $installment->plan;
        $clinic = $plan->clinic;
        $patient = $plan->patient;

        // Normalize 'tr_TR' → 'tr' so Laravel lang/ dirs and Carbon both resolve correctly.
        $lang = strtolower(explode('_', $clinic->locale)[0]);

        $body = $this->renderer->resolve($clinic, $type, [
            'clinic' => $clinic->name,
            'patient' => trim("{$patient->first_name} {$patient->last_name}"),
            'amount' => (string) $installment->amount,
            'date' => $installment->due_date->locale($lang)->translatedFormat('d F Y'),
        ]);

        return new SmsMessage(
            phone: $patient->getRawOriginal('phone'),
            body: $body,
            type: $type,
            clinicId: $plan->clinic_id,
            patientId: $plan->patient_id,
            loggableType: self::LOGGABLE_TYPE,
            loggableId: $installment->id,
        );
    }
}
