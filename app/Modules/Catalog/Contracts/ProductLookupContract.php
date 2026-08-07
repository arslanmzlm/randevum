<?php

namespace App\Modules\Catalog\Contracts;

use Illuminate\Support\Collection;

/**
 * Read seam for catalog product data used by other modules (treatment product-line editor).
 * Consumers import this contract; never the concrete ProductCatalogService or Product model.
 */
interface ProductLookupContract
{
    /**
     * Active products projected for the treatment product-line editor, ordered by name.
     *
     * @return Collection<int, array{id: int, name: string, price: string, unit: string, current_stock: int}>
     */
    public function activeForTreatment(): Collection;
}
