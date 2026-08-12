<?php

namespace App\Modules\Medical\Http\Requests;

use App\Enums\AlcoholUse;
use App\Enums\BloodType;
use App\Enums\DiabetesStatus;
use App\Enums\PregnancyStatus;
use App\Enums\SmokingStatus;
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
            'blood_type' => ['nullable', Rule::enum(BloodType::class)],
            'height_cm' => ['nullable', 'integer', 'min:1', 'max:300'],
            'weight_kg' => ['nullable', 'numeric', 'min:1', 'max:500'],
            'smoking' => ['nullable', Rule::enum(SmokingStatus::class)],
            'alcohol' => ['nullable', Rule::enum(AlcoholUse::class)],
            'diabetes' => ['nullable', Rule::enum(DiabetesStatus::class)],
            'pregnancy' => ['nullable', Rule::enum(PregnancyStatus::class)],
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
