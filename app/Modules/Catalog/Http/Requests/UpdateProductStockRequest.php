<?php

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
