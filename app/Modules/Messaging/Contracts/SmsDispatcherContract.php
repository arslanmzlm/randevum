<?php

namespace App\Modules\Messaging\Contracts;

use App\Enums\SmsType;
use App\Modules\Messaging\Data\SmsMessage;

/**
 * The single front door for clinic-scoped SMS sends.
 * Implementations resolve the clinic's per-type preference and either
 * dispatch SendSmsJob or log a Skipped entry. OTP / platform sends
 * (clinicId === null) always bypass the gate.
 *
 * Future quota checks (3.9a) slot in here — this is the intended seam.
 */
interface SmsDispatcherContract
{
    /**
     * @return bool true when the send job was queued, false when the gate skipped it
     *              (disabled by clinic, quota exceeded, or no phone on file).
     */
    public function dispatch(SmsMessage $message): bool;

    /**
     * Second idempotency guard: true when an sms_logs row for this loggable+type
     * already exists with status=Sent. Callers use this before dispatching to avoid
     * a double-send when the reminder flag failed to persist.
     */
    public function wasSent(string $loggableType, int $loggableId, SmsType $type): bool;
}
