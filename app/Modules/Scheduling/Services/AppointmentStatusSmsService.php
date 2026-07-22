<?php

namespace App\Modules\Scheduling\Services;

use App\Enums\SmsType;
use App\Models\Appointment;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Contracts\SmsTemplateRendererContract;
use App\Modules\Messaging\Data\SmsMessage;
use Carbon\Carbon;

class AppointmentStatusSmsService
{
    private const LOGGABLE_TYPE = 'appointment';

    public function __construct(
        private SmsDispatcherContract $dispatcher,
        private SmsTemplateRendererContract $renderer,
    ) {}

    /**
     * Dispatch the AppointmentCreated SMS for a newly booked appointment.
     * Safe to call post-commit: a dispatcher failure never propagates.
     */
    public function sendCreated(Appointment $appointment): void
    {
        $this->send($appointment, SmsType::AppointmentCreated);
    }

    /**
     * Dispatch the AppointmentCancelled SMS for a single cancelled appointment.
     * Safe to call post-commit: a dispatcher failure never propagates.
     */
    public function sendCancelled(Appointment $appointment): void
    {
        $this->send($appointment, SmsType::AppointmentCancelled);
    }

    /**
     * Dispatch the AppointmentRescheduled SMS when a real start-time move occurred.
     * Safe to call post-commit: a dispatcher failure never propagates.
     */
    public function sendRescheduled(Appointment $appointment): void
    {
        $this->send($appointment, SmsType::AppointmentRescheduled);
    }

    /**
     * Dispatch AppointmentCreated for each appointment in the iterable (follow-up batch).
     *
     * @param  iterable<Appointment>  $appointments
     */
    public function sendCreatedMany(iterable $appointments): void
    {
        foreach ($appointments as $appointment) {
            $this->sendCreated($appointment);
        }
    }

    /**
     * Dispatch AppointmentCancelled for each appointment in the iterable (bulk/offboarding cancel).
     *
     * @param  iterable<Appointment>  $appointments
     */
    public function sendCancelledMany(iterable $appointments): void
    {
        foreach ($appointments as $appointment) {
            $this->sendCancelled($appointment);
        }
    }

    private function send(Appointment $appointment, SmsType $type): void
    {
        try {
            $appointment->loadMissing('patient', 'clinic', 'doctor.user');

            $message = $this->buildMessage($appointment, $type);

            $this->dispatcher->dispatch($message);
        } catch (\Throwable) {
            // SMS dispatch is a non-fatal side-effect: failures must never surface
            // as booking/cancel/reschedule errors or roll back committed rows.
        }
    }

    private function buildMessage(Appointment $appointment, SmsType $type): SmsMessage
    {
        $clinic = $appointment->clinic;
        $patient = $appointment->patient;

        // Convert UTC starts_at to clinic-local wall-clock time for the SMS body.
        $localTime = Carbon::parse($appointment->starts_at)->setTimezone($clinic->timezone);

        // Normalize 'tr_TR' → 'tr' so Laravel lang/ dirs resolve correctly.
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
