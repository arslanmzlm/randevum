<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Models\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Summary block for the appointment detail page.
 *
 * All timestamps are ISO 8601 UTC — the frontend formats them via useDateTime().
 *
 * @mixin Appointment
 */
class AppointmentDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'patient_id' => $this->patient_id,
            'patient_name' => trim($this->patient->first_name.' '.$this->patient->last_name),
            'doctor_id' => $this->doctor_id,
            'doctor_name' => $this->doctor->display_name,
            'service_name' => $this->service?->name,
            'appointment_type' => $this->appointmentType
                ? ['name' => $this->appointmentType->name, 'color' => $this->appointmentType->color]
                : null,
            'status' => $this->status->value,
            'is_walk_in' => $this->is_walk_in,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'treatment_id' => $this->whenLoaded('treatment', fn () => $this->treatment?->id),
            'created_by_name' => $this->creator?->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
