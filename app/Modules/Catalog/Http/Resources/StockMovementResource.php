<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * List-row shape for the per-product stock movement history page.
 *
 * creator/treatment.patient are eager-loaded by the repository — no N+1 here.
 *
 * @mixin StockMovement
 */
class StockMovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => $this->quantity,
            'balance_after' => $this->balance_after,
            'reason' => $this->reason->value,
            'note' => $this->note,
            'created_at' => $this->created_at->toIso8601String(),
            'created_by_name' => $this->creator?->name,
            'treatment_id' => $this->treatment_id,
            'patient_name' => $this->treatment?->patient !== null
                ? trim($this->treatment->patient->first_name.' '.$this->treatment->patient->last_name)
                : null,
        ];
    }
}
