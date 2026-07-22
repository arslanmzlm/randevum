<?php

namespace App\Modules\Medical\Http\Requests;

use App\Models\PodiatryAnamnesis;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAnamnesisRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('updateAnamnesis', $patient).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'blood_type' => ['nullable', 'string', Rule::in(PodiatryAnamnesis::BLOOD_TYPES)],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:300'],
            'weight_kg' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'smoking' => ['nullable', 'string', Rule::in(PodiatryAnamnesis::SMOKING)],
            'alcohol' => ['nullable', 'string', Rule::in(PodiatryAnamnesis::ALCOHOL)],
            'diabetes' => ['nullable', 'string', Rule::in(PodiatryAnamnesis::DIABETES)],
            'hypertension' => ['boolean'],
            'cardiovascular' => ['boolean'],
            'blood_thinners' => ['boolean'],
            'regular_medications' => ['nullable', 'string', 'max:2000'],
            'other_chronic' => ['nullable', 'string', 'max:2000'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'pregnancy' => ['nullable', 'string', Rule::in(PodiatryAnamnesis::PREGNANCY)],
            'foot_surgery_history' => ['nullable', 'string', 'max:2000'],
            'diabetic_foot_history' => ['boolean'],
            'current_foot_complaint' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
