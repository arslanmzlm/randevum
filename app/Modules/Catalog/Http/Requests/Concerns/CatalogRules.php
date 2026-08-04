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
     * The `name` column is shared by every entity form, so validation.attributes.name has to stay
     * generic ("Ad"). A form that wants its own label maps it here — the text itself still lives
     * in the lang file, never inline.
     *
     * @return array<string, string>
     */
    protected function nameAttribute(string $key): array
    {
        return ['name' => __("validation.attributes.{$key}")];
    }

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
