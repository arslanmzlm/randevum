<?php

namespace App\Modules\Messaging\Providers;

use App\Modules\Messaging\Contracts\SmsProviderInterface;
use App\Modules\Messaging\Data\SmsResponse;
use Illuminate\Support\Str;

/**
 * No-op provider for test/local: never hits the network, always succeeds.
 */
final class NullSmsProvider implements SmsProviderInterface
{
    public function send(string $phone, string $body): SmsResponse
    {
        return SmsResponse::success('null-'.Str::uuid()->toString());
    }
}
