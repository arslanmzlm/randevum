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
            // whereNull('deleted_at'): Rule::exists builds a raw query, so SoftDeletes does not
            // apply — without it a soft-deleted patient's id opens a new follow-up.
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')
                    ->where('clinic_id', $clinicId)
                    ->whereNull('deleted_at'),
            ],
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
