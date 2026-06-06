<?php

namespace App\Modules\Scheduling\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DayScheduleRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
