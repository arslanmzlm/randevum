<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\PodiatryAnamnesis;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Flat anamnesis field shape shared by the patient-show and treatment-process props.
 *
 * @mixin PodiatryAnamnesis
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
            'smoking' => $this->smoking,
            'alcohol' => $this->alcohol,
            'diabetes' => $this->diabetes,
            'hypertension' => (bool) $this->hypertension,
            'cardiovascular' => (bool) $this->cardiovascular,
            'blood_thinners' => (bool) $this->blood_thinners,
            'regular_medications' => $this->regular_medications,
            'other_chronic' => $this->other_chronic,
            'allergies' => $this->allergies,
            'pregnancy' => $this->pregnancy,
            'foot_surgery_history' => $this->foot_surgery_history,
            'diabetic_foot_history' => (bool) $this->diabetic_foot_history,
            'current_foot_complaint' => $this->current_foot_complaint,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
