<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\Treatment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Treatment shape for the Process screen (Draft only).
 *
 * @mixin Treatment
 */
class TreatmentProcessResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'appointment' => [
                'id' => $this->appointment->id,
                'starts_at' => $this->appointment->starts_at->toIso8601String(),
                'is_walk_in' => $this->appointment->is_walk_in,
                'service_id' => $this->appointment->service_id,
                'appointment_type' => $this->appointment->appointmentType ? [
                    'name' => $this->appointment->appointmentType->name,
                    'color' => $this->appointment->appointmentType->color,
                ] : null,
            ],
            'patient' => [
                'id' => $this->patient->id,
                'full_name' => trim($this->patient->first_name.' '.$this->patient->last_name),
                'phone' => $this->patient->getRawOriginal('phone'),
                'notes' => $this->patient->notes,
            ],
            'doctor' => [
                'id' => $this->doctor->id,
                'display_name' => $this->doctor->display_name,
            ],
            'details' => [
                'complaint' => $this->details?->complaint,
                'diagnosis' => $this->details?->diagnosis,
                'treatment_process' => $this->details?->treatment_process,
            ],
            'notes' => $this->notes,
        ];
    }
}
