<?php

namespace App\Modules\Billing\Http\Resources;

use App\Models\Expense;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical expense shape shared by the finance page's all-clinic list and the
 * Giderlerim own-only list. creator_name identifies "who entered it" on the
 * all-clinic list; on Giderlerim it is always the viewer themself.
 *
 * @mixin Expense
 */
class ExpenseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_date' => $this->expense_date->format('Y-m-d'),
            'amount' => $this->amount,
            'category' => $this->category,
            'description' => $this->description,
            'created_by' => $this->created_by,
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
