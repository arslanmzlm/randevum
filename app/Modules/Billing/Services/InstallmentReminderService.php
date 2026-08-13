<?php

namespace App\Modules\Billing\Services;

use App\Enums\InstallmentStatus;
use App\Enums\SmsType;
use App\Models\PaymentPlanInstallment;
use App\Modules\Billing\Repositories\PaymentPlanRepository;
use App\Modules\Messaging\Contracts\ReminderWaveContract;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Contracts\SmsTemplateRendererContract;
use App\Modules\Messaging\Data\SmsMessage;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

class InstallmentReminderService
{
    private const LOGGABLE_TYPE = 'payment_plan_installment';

    public function __construct(
        private PaymentPlanRepository $repository,
        private ReminderWaveContract $waves,
        private SmsDispatcherContract $dispatcher,
        private SmsTemplateRendererContract $renderer,
        private InstallmentSettlementService $settlement,
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
     *
     * @return bool true when the SMS was actually dispatched, false when the gate skipped
     *              it (e.g. the clinic disabled installment reminders) — the controller
     *              uses this to show a truthful toast instead of always "sent".
     *
     * @throws ValidationException when the installment is not open (already fully
     *                             collected or cancelled — nothing left to remind about).
     */
    public function sendManual(PaymentPlanInstallment $installment): bool
    {
        if (! in_array($installment->status, [InstallmentStatus::Pending, InstallmentStatus::PartiallyPaid], true)) {
            throw ValidationException::withMessages([
                'installment' => [__('payment_plan.errors.not_pending')],
            ]);
        }

        $installment->loadMissing('plan.patient', 'plan.clinic');

        $message = $this->buildMessage($installment, SmsType::InstallmentDue1d);

        return $this->dispatcher->dispatch($message);
    }

    private function processWave(string $onDate, string $flagColumn, SmsType $type): int
    {
        return $this->waves->dispatchWave(
            $this->repository->dueInstallments($onDate, $flagColumn),
            self::LOGGABLE_TYPE,
            $type,
            fn (PaymentPlanInstallment $installment): SmsMessage => $this->buildMessage($installment, $type),
            fn (PaymentPlanInstallment $installment) => $this->repository->markReminderSent($installment, $flagColumn),
        );
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
            // The remaining amount, not the full installment — a partially-collected
            // installment reminds for what is actually due.
            'amount' => $this->settlement->remainingFromLoaded($installment),
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
