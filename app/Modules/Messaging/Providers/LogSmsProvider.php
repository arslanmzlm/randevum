<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Contracts\SmsProviderInterface;
use App\Modules\Messaging\Data\SmsResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Local dev: writes the SMS to storage/logs/sms.log instead of sending.
 * Set SMS_PROVIDER=log in .env to read OTP codes locally.
 */
final class LogSmsProvider implements SmsProviderInterface
{
    public function send(string $phone, string $body): SmsResponse
    {
        $reference = 'log-'.Str::uuid()->toString();

        Log::channel('sms')->info('SMS', [
            'phone' => $phone,
            'body' => $body,
            'reference' => $reference,
        ]);

        return SmsResponse::success($reference);
    }
}
