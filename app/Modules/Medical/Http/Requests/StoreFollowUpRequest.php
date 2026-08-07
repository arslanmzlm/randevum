<?php

namespace App\Modules\Medical\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFollowUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is handled in the controller via authorize().
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $clinicId = app(ClinicContext::class)->id();

        return [
            'patient_id' => ['required', 'integer', Rule::exists('patients', 'id')->where('clinic_id', $clinicId)],
            'case_id' => ['nullable', 'integer', Rule::exists('cases', 'id')->where('clinic_id', $clinicId)],
            'follow_up_type_id' => [
                'required',
                'integer',
                Rule::exists('follow_up_types', 'id')
                    ->where('clinic_id', $clinicId)
                    ->where('is_active', true)
                    ->whereNull('deleted_at'),
            ],
            'due_date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
