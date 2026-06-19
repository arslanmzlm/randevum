<?php

namespace App\Modules\Identity\Http\Requests;

use App\Http\Requests\Concerns\NormalizesTrPhone;
use Illuminate\Foundation\Http\FormRequest;

class OtpVerifyRequest extends FormRequest
{
    use NormalizesTrPhone;

    /**
     * Public OTP endpoint — no auth required to verify a code.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'phone:TR'],
            'code' => ['required', 'digits:'.config('platform.otp.length')],
        ];
    }

    /**
     * The validated phone number formatted as E.164, ready for cache/DB lookups.
     */
    public function e164Phone(): string
    {
        return $this->toE164($this->validated('phone'));
    }
}
