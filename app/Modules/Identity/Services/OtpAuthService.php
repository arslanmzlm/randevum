<?php

namespace App\Modules\Identity\Services;

use App\Enums\SmsType;
use App\Models\User;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Data\SmsMessage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/**
 * OTP passwordless phone login. Codes are hashed in cache (key `auth:otp:{e164}`);
 * request() is intentionally silent on unknown phones (no user enumeration).
 */
class OtpAuthService
{
    public function __construct(
        private SmsDispatcherContract $smsDispatcher,
    ) {}

    public function request(string $e164): void
    {
        $user = User::where('phone', $e164)
            ->whereNotNull('phone_verified_at')
            ->first();

        if (! $user) {
            return;
        }

        $resendKey = "auth:otp:resend:{$e164}";

        if (! Cache::add($resendKey, true, config('platform.otp.resend_throttle'))) {
            return;
        }

        $length = config('platform.otp.length');
        $code = str_pad(
            (string) random_int(0, (int) str_repeat('9', $length)),
            $length,
            '0',
            STR_PAD_LEFT
        );
        $ttl = config('platform.otp.ttl');

        Cache::put("auth:otp:{$e164}", Hash::make($code), $ttl);
        Cache::put("auth:otp:attempts:{$e164}", 0, $ttl);

        $this->smsDispatcher->dispatch(new SmsMessage(
            phone: $e164,
            body: __('auth.otp.sms_body', ['code' => $code, 'ttl' => $ttl]),
            type: SmsType::Otp,
            clinicId: null,
        ));
    }

    public function verify(string $e164, string $code): ?User
    {
        $hash = Cache::get("auth:otp:{$e164}");

        if (! $hash) {
            return null;
        }

        $attemptsKey = "auth:otp:attempts:{$e164}";
        $attempts = (int) Cache::increment($attemptsKey);

        if ($attempts > config('platform.otp.max_attempts')) {
            return null;
        }

        if (! Hash::check($code, $hash)) {
            return null;
        }

        Cache::forget("auth:otp:{$e164}");
        Cache::forget("auth:otp:attempts:{$e164}");
        Cache::forget("auth:otp:resend:{$e164}");

        $user = User::where('phone', $e164)
            ->whereNotNull('phone_verified_at')
            ->first();

        if (! $user) {
            return null;
        }

        Auth::guard('web')->login($user);
        session()->regenerate();

        return $user;
    }
}
