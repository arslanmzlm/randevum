<?php

namespace App\Modules\Billing\Http\Resources;

use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class ManualIncomeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'paid_at' => $this->paid_at->toIso8601String(),
            'amount' => $this->amount,
            'payment_method' => $this->payment_method->value,
            'category' => $this->category,
            'note' => $this->note,
            'created_by' => $this->created_by,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
