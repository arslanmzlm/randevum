<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\Anamnesis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Flat anamnesis field shape shared by the patient-show and treatment-process props.
 *
 * @mixin Anamnesis
 */
class AnamnesisResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'blood_type' => $this->blood_type,
            'height_cm' => $this->height_cm,
            'weight_kg' => $this->weight_kg !== null ? (float) $this->weight_kg : null,
            'bmi' => $this->bmi(),
            'smoking' => $this->smoking,
            'alcohol' => $this->alcohol,
            'diabetes' => $this->diabetes,
            'pregnancy' => $this->pregnancy,
            'hypertension' => (bool) $this->hypertension,
            'cardiovascular' => (bool) $this->cardiovascular,
            'respiratory' => (bool) $this->respiratory,
            'kidney_liver' => (bool) $this->kidney_liver,
            'thyroid' => (bool) $this->thyroid,
            'epilepsy' => (bool) $this->epilepsy,
            'blood_thinners' => (bool) $this->blood_thinners,
            'bleeding_disorder' => (bool) $this->bleeding_disorder,
            'infectious_disease' => (bool) $this->infectious_disease,
            'infectious_disease_note' => $this->infectious_disease_note,
            'regular_medications' => $this->regular_medications,
            'other_chronic' => $this->other_chronic,
            'allergies' => $this->allergies,
            'surgery_history' => $this->surgery_history,
            'family_history' => $this->family_history,
            'menstrual_notes' => $this->menstrual_notes,
            'physician_name' => $this->physician_name,
            'physician_phone' => $this->physician_phone,
            'extra' => $this->extra ?? [],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
