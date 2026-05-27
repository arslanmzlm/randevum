<?php

namespace App\Modules\Messaging\Contracts;

use App\Modules\Messaging\Data\SmsResponse;

interface SmsProviderInterface
{
    /**
     * Send a single SMS. Implementations must not throw on provider errors —
     * map them to an unsuccessful SmsResponse so SendSmsJob can log the failure.
     */
    public function send(string $phone, string $body): SmsResponse;
}
