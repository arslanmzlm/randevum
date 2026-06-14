<?php

namespace App\Modules\Messaging\Contracts;

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
    public function dispatch(SmsMessage $message): void;
}
