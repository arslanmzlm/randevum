<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Support\ClinicContext;
use App\Support\ValidationRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleExceptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Authorization is handled in the controller via policy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clinicId = app(ClinicContext::class)->id();

        return [
            'scope' => ['required', Rule::in(['doctor', 'clinic'])],
            'doctor_id' => [
                'required_if:scope,doctor',
                'nullable',
                'integer',
                Rule::exists('doctors', 'id')->where('clinic_id', $clinicId),
            ],
            'is_all_day' => ['boolean'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after_or_equal:starts_at'],
            'reason' => ['nullable', ...ValidationRules::reason(255)],
        ];
    }
}
