<?php

namespace App\Modules\Catalog\Http\Requests\Concerns;

use App\Support\ValidationRules;

/**
 * Shared rule fragments for catalog (service/product) form requests, so store and
 * update validate the same columns identically.
 */
trait CatalogRules
{
    /**
     * @return array<string, mixed>
     */
    protected function serviceRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', ...ValidationRules::money(0)],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:480'],
            'default_complaint' => ['nullable', 'string', 'max:5000'],
            'default_diagnosis' => ['nullable', 'string', 'max:5000'],
            'default_treatment_process' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function productRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'brand' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100'],
            'unit' => ['required', 'string', 'max:20'],
            'price' => ['required', ...ValidationRules::money(0)],
            'is_active' => ['boolean'],
        ];
    }
}
