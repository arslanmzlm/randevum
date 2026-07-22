<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\SmsType;
use App\Models\Appointment;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Contracts\SmsTemplateRendererContract;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Scheduling\Repositories\AppointmentReminderRepository;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class AppointmentReminderService
{
    private const LOGGABLE_TYPE = 'appointment';

    public function __construct(
        private AppointmentReminderRepository $repository,
        private SmsDispatcherContract $dispatcher,
        private SmsTemplateRendererContract $renderer,
    ) {}

    /**
     * Dispatch the 24h and 1h reminder waves for the given "now" moment.
     * Both windows are ±5 min around the target offset from now, in UTC.
     * Returns the total number of dispatch calls made (Skipped + Queued).
     */
    public function sendDueReminders(CarbonInterface $now): int
    {
        $dispatched = 0;

        $dispatched += $this->processWindow(
            $now->copy()->addHours(24)->subMinutes(5),
            $now->copy()->addHours(24)->addMinutes(5),
            'reminder_24h_sent',
            SmsType::Reminder24h,
        );

        $dispatched += $this->processWindow(
            $now->copy()->addHour()->subMinutes(5),
            $now->copy()->addHour()->addMinutes(5),
            'reminder_1h_sent',
            SmsType::Reminder1h,
        );

        return $dispatched;
    }

    /**
     * Manual "send reminder now" triggered by a staff member. Uses SmsType::Reminder24h
     * as the canonical reminder type. Does NOT read or set reminder_*_sent flags —
     * staff may deliberately resend. Respects the clinic toggle via the gate.
     */
    public function sendManual(Appointment $appointment): void
    {
        $appointment->loadMissing('patient', 'clinic', 'doctor.user');

        $message = $this->buildMessage($appointment, SmsType::Reminder24h);

        $this->dispatcher->dispatch($message);
    }

    private function processWindow(
        CarbonInterface $fromUtc,
        CarbonInterface $toUtc,
        string $flagColumn,
        SmsType $type,
    ): int {
        $appointments = $this->repository->dueForReminder($fromUtc, $toUtc, $flagColumn);

        $count = 0;

        foreach ($appointments as $appointment) {
            // Second idempotency guard: if a Sent log already exists for this
            // appointment+type, skip dispatch even though the flag was false.
            if ($this->dispatcher->wasSent(self::LOGGABLE_TYPE, $appointment->id, $type)) {
                $this->repository->markReminderSent($appointment, $flagColumn);

                continue;
            }

            $message = $this->buildMessage($appointment, $type);

            $this->dispatcher->dispatch($message);

            $this->repository->markReminderSent($appointment, $flagColumn);

            $count++;
        }

        return $count;
    }

    private function buildMessage(Appointment $appointment, SmsType $type): SmsMessage
    {
        $clinic = $appointment->clinic;
        $patient = $appointment->patient;

        // Convert UTC starts_at to clinic-local wall-clock time for the SMS body.
        $localTime = Carbon::parse($appointment->starts_at)->setTimezone($clinic->timezone);

        // Normalize 'tr_TR' → 'tr' so Laravel lang/ dirs and Carbon both resolve correctly.
        $lang = strtolower(explode('_', $clinic->locale)[0]);

        $body = $this->renderer->resolve($clinic, $type, [
            'clinic' => $clinic->name,
            'date' => $localTime->locale($lang)->translatedFormat('d F Y'),
            'time' => $localTime->format('H:i'),
            'patient' => trim("{$patient->first_name} {$patient->last_name}"),
            'doctor' => $appointment->doctor?->displayName ?? '',
        ]);

        return new SmsMessage(
            phone: $patient->getRawOriginal('phone'),
            body: $body,
            type: $type,
            clinicId: $appointment->clinic_id,
            patientId: $appointment->patient_id,
            loggableType: self::LOGGABLE_TYPE,
            loggableId: $appointment->id,
        );
    }
}
