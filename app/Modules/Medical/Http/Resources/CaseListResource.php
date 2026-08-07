<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\CaseRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Compact shape for the cases index list.
 *
 * @mixin CaseRecord
 */
class CaseListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'patient' => [
                'id' => (int) $this->patient_id,
                'full_name' => trim($this->patient->first_name.' '.$this->patient->last_name),
            ],
            'doctor' => [
                'id' => (int) $this->doctor_id,
                'display_name' => $this->doctor->display_name,
            ],
            'treatments_count' => (int) $this->treatments_count,
            'opened_at' => $this->opened_at->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            // Earliest OPEN follow-up's due_date, from the paginateForActiveClinic() withMin aggregate.
            'follow_up_date' => $this->next_follow_up_date,
        ];
    }
}
