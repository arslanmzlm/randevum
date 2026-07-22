<?php

namespace App\Modules\Messaging\Services;

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\SmsLog;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Messaging\Repositories\ClinicSmsSettingRepository;

class SmsDispatcher implements SmsDispatcherContract
{
    public function __construct(
        private ClinicSmsSettingRepository $settings,
        private SmsQuotaService $quota,
    ) {}

    /**
     * Gate + dispatch. Platform sends (clinicId === null) always bypass the gate —
     * OTP must never be blocked by a clinic preference. Clinic-scoped sends are
     * checked against the per-type preference; disabled types are logged as Skipped.
     * A clinic over its monthly SMS quota is logged as Skipped with error='quota exceeded'.
     * A null phone (patient has no registered number) is logged as Skipped with
     * error='no phone' so it appears in the future SMS-log UI without a retry.
     */
    public function dispatch(SmsMessage $message): bool
    {
        if ($message->clinicId === null || ! $message->type->isClinicScoped()) {
            SendSmsJob::dispatch($message);

            return true;
        }

        if (! $this->settings->isEnabled($message->clinicId, $message->type)) {
            $this->writeSkipped($message, 'disabled by clinic');

            return false;
        }

        if (! $this->quota->hasRoom($message->clinicId)) {
            $this->writeSkipped($message, 'quota exceeded');

            return false;
        }

        if ($message->phone === null) {
            $this->writeSkipped($message, 'no phone');

            return false;
        }

        SendSmsJob::dispatch($message);

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function wasSent(string $loggableType, int $loggableId, SmsType $type): bool
    {
        // No clinic_id filter: callers pass none, and loggable_type+id already pins a
        // single tenant's row. Cross-tenant collision isn't possible on this key.
        return SmsLog::withoutGlobalScopes()
            ->where('loggable_type', $loggableType)
            ->where('loggable_id', $loggableId)
            ->where('type', $type->value)
            ->where('status', SmsStatus::Sent->value)
            ->exists();
    }

    private function writeSkipped(SmsMessage $message, string $error): void
    {
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
            'error' => $error,
        ]);
    }
}
