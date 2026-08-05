<?php

namespace App\Modules\Medical\Http\Resources;

use App\Models\CaseRecord;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Full shape for the case detail page.
 *
 * @mixin CaseRecord
 */
class CaseShowResource extends JsonResource
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
            'notes' => $this->notes,
            'opened_at' => $this->opened_at->toIso8601String(),
            'closed_at' => $this->closed_at?->toIso8601String(),
            'suspended_at' => $this->suspended_at?->toIso8601String(),
            'follow_up_date' => $this->follow_up_date?->format('Y-m-d'),
            'follow_up_note' => $this->follow_up_note,
            'patient' => [
                'id' => (int) $this->patient_id,
                'full_name' => trim($this->patient->first_name.' '.$this->patient->last_name),
            ],
            'doctor' => [
                'id' => (int) $this->doctor_id,
                'display_name' => $this->doctor->display_name,
            ],
            // The clinical trio rides along so the case page can show what was actually done
            // without a round trip to each treatment.
            'treatments' => $this->treatments->map(fn ($t) => [
                'id' => $t->id,
                'title' => $t->serviceLines->first()?->service?->name,
                'status' => $t->status->value,
                'completed_at' => $t->completed_at?->toIso8601String(),
                'total_amount' => (string) $t->total_amount,
                'complaint' => $t->details?->complaint,
                'diagnosis' => $t->details?->diagnosis,
                'treatment_process' => $t->details?->treatment_process,
            ])->all(),
        ];
    }
}
