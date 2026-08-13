<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\Treatment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List-row shape for the treatments index.
 *
 * All timestamps are ISO 8601 UTC — the frontend formats them via useDateTime().
 *
 * @mixin Treatment
 */
class TreatmentListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'created_at' => $this->created_at->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'patient' => [
                'id' => (int) $this->patient_id,
                'full_name' => trim($this->patient->first_name.' '.$this->patient->last_name),
                'is_deleted' => $this->patient->trashed(),
            ],
            'doctor' => [
                'id' => (int) $this->doctor_id,
                'display_name' => $this->doctor->display_name,
            ],
            'service_names' => $this->serviceLines
                ->sortBy('sort_order')
                ->map(fn ($line) => $line->service?->name)
                ->filter()
                ->values()
                ->all(),
            'total_amount' => $this->total_amount,
        ];
    }
}
