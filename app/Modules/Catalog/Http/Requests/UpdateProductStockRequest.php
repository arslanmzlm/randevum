<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Enums\StockMovementReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductStockRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('manageStock', $product).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // No min:0 — stock may go negative (MVP design decision).
            'current_stock' => ['required', 'integer'],
            // Nullable: defaults to ManualAdjustment in the controller when absent.
            'reason' => ['nullable', Rule::enum(StockMovementReason::class)
                ->only([StockMovementReason::ManualAdjustment, StockMovementReason::Return])],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
