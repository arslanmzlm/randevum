<?php

namespace App\Modules\Catalog\Http\Requests;

use App\Modules\Catalog\Http\Requests\Concerns\CatalogRules;
use Illuminate\Foundation\Http\FormRequest;

class StoreProductRequest extends FormRequest
{
    use CatalogRules;

    /**
     * Authorization is handled by the controller via $this->authorize('create', Product::class).
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
            ...$this->productRules(),
            'current_stock' => ['nullable', 'integer'],
        ];
    }
}
