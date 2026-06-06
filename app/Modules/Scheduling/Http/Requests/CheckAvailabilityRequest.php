<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CheckAvailabilityRequest extends FormRequest
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
            'doctor_id' => [
                'required',
                'integer',
                Rule::exists('doctors', 'id')->where('clinic_id', $clinicId),
            ],
            'starts_at' => ['required', 'date'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'service_id' => [
                'nullable',
                'integer',
                Rule::exists('services', 'id')->where('clinic_id', $clinicId),
            ],
            'is_walk_in' => ['boolean'],
        ];
    }
}
