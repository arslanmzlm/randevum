<?php

namespace App\Modules\Medical\Http\Requests;

use App\Models\Anamnesis;
use App\Modules\Medical\Services\AnamnesisFieldService;
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
        $anamnesisFieldService = app(AnamnesisFieldService::class);
        $activeFields = $anamnesisFieldService->definitionsForActiveClinic();

        return [
            'blood_type' => ['nullable', 'string', Rule::in(Anamnesis::BLOOD_TYPES)],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:300'],
            'weight_kg' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'smoking' => ['nullable', 'string', Rule::in(Anamnesis::SMOKING)],
            'alcohol' => ['nullable', 'string', Rule::in(Anamnesis::ALCOHOL)],
            'diabetes' => ['nullable', 'string', Rule::in(Anamnesis::DIABETES)],
            'pregnancy' => ['nullable', 'string', Rule::in(Anamnesis::PREGNANCY)],
            'hypertension' => ['boolean'],
            'cardiovascular' => ['boolean'],
            'respiratory' => ['boolean'],
            'kidney_liver' => ['boolean'],
            'thyroid' => ['boolean'],
            'epilepsy' => ['boolean'],
            'blood_thinners' => ['boolean'],
            'bleeding_disorder' => ['boolean'],
            'infectious_disease' => ['boolean'],
            'infectious_disease_note' => ['nullable', 'string', 'max:500'],
            'regular_medications' => ['nullable', 'string', 'max:2000'],
            'other_chronic' => ['nullable', 'string', 'max:2000'],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'surgery_history' => ['nullable', 'string', 'max:2000'],
            'family_history' => ['nullable', 'string', 'max:2000'],
            'menstrual_notes' => ['nullable', 'string', 'max:2000'],
            'extra' => ['nullable', 'array'],
            ...$anamnesisFieldService->validationRules($activeFields),
        ];
    }
}
