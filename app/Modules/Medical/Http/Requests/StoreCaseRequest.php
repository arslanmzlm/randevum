<?php

namespace App\Modules\Medical\Http\Requests;

use App\Support\ClinicContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCaseRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            // whereNull('deleted_at'): Rule::exists builds a raw query, so SoftDeletes does not
            // apply — without it a soft-deleted patient's id opens a new case.
            'patient_id' => [
                'required',
                'integer',
                Rule::exists('patients', 'id')
                    ->where('clinic_id', $clinicId)
                    ->whereNull('deleted_at'),
            ],
            'doctor_id' => ['nullable', 'integer', Rule::exists('doctors', 'id')->where('clinic_id', $clinicId)],
            'treatment_ids' => ['nullable', 'array'],
            'treatment_ids.*' => ['integer'],
        ];
    }
}
