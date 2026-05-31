<?php

namespace App\Modules\Catalog\Http\Resources;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Canonical product shape shared by the index and edit pages.
 *
 * Page-level flags (canManage) are controller-level Inertia props — they do NOT live here.
 *
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'brand' => $this->brand,
            'category' => $this->category,
            'sku' => $this->sku,
            'unit' => $this->unit,
            'price' => $this->price,
            'current_stock' => $this->current_stock,
            'is_active' => $this->is_active,
        ];
    }
}
