<?php

namespace App\Modules\Medical\Http\Requests;

use App\Models\Treatment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class VoidTreatmentRequest extends FormRequest
{
    /**
     * Authorization is handled by the controller via $this->authorize('void', $treatment).
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
        $treatment = $this->route('treatment');
        $treatmentId = $treatment instanceof Treatment ? $treatment->id : 0;

        return [
            // Omitting the key means "return nothing to stock". The UI ships every product
            // line pre-checked, so the only way to land here empty is a caller that made no
            // choice — and under-returning is recoverable by hand, inflating stock is not.
            'restock_line_ids' => ['nullable', 'array'],
            'restock_line_ids.*' => [
                'integer',
                Rule::exists('treatment_products', 'id')->where('treatment_id', $treatmentId),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    public function restockLineIds(): array
    {
        return array_map('intval', $this->input('restock_line_ids', []));
    }
}
