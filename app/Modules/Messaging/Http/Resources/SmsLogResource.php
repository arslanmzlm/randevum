<?php

namespace App\Modules\Messaging\Http\Resources;

use App\Models\SmsLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List-row shape for the SMS log index and patient communication-history section.
 *
 * All timestamps are ISO 8601 UTC — the frontend formats them via useDateTime().
 *
 * @mixin SmsLog
 */
class SmsLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'phone' => $this->phone,
            'patient_name' => $this->patient !== null
                ? trim($this->patient->first_name.' '.$this->patient->last_name)
                : null,
            'patient_id' => $this->patient_id,
            'body' => $this->body,
            'error' => $this->error,
            'created_at' => $this->created_at->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
        ];
    }
}
