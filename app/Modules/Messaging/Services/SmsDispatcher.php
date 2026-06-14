<?php

namespace App\Modules\Messaging\Services;

use App\Enums\SmsStatus;
use App\Models\SmsLog;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Messaging\Repositories\ClinicSmsSettingRepository;

class SmsDispatcher implements SmsDispatcherContract
{
    public function __construct(
        private ClinicSmsSettingRepository $settings,
    ) {}

    /**
     * Gate + dispatch. Platform sends (clinicId === null) always bypass the gate —
     * OTP must never be blocked by a clinic preference. Clinic-scoped sends are
     * checked against the per-type preference; disabled types are logged as Skipped.
     */
    public function dispatch(SmsMessage $message): void
    {
        if ($message->clinicId === null || ! $message->type->isClinicScoped()) {
            SendSmsJob::dispatch($message);

            return;
        }

        if (! $this->settings->isEnabled($message->clinicId, $message->type)) {
            SmsLog::withoutGlobalScopes()->create([
                'clinic_id' => $message->clinicId,
                'patient_id' => $message->patientId,
                'phone' => $message->phone,
                'type' => $message->type,
                'loggable_type' => $message->loggableType,
                'loggable_id' => $message->loggableId,
                'body' => $message->body,
                'status' => SmsStatus::Skipped,
                'scheduled_at' => now(),
                'error' => 'disabled by clinic',
            ]);

            return;
        }

        SendSmsJob::dispatch($message);
    }
}
