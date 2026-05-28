<?php

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Propaganistas\LaravelPhone\PhoneNumber;

class OtpRequestRequest extends FormRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'phone:TR'],
        ];
    }

    /**
     * The validated phone number formatted as E.164, ready for cache/DB lookups.
     */
    public function e164Phone(): string
    {
        return (new PhoneNumber($this->validated('phone'), 'TR'))->formatE164();
    }
}
