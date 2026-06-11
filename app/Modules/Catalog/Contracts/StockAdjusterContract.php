<?php

namespace App\Modules\Catalog\Contracts;

/**
 * Atomic stock adjustment seam used by the Medical module on treatment completion/void.
 * Medical imports this contract; never the concrete ProductCatalogService.
 */
interface StockAdjusterContract
{
    /**
     * Atomically adjust a product's current_stock by $delta (positive = restock, negative = deduct).
     * Stock may go negative — no floor guard is applied here.
     */
    public function adjust(int $productId, int $delta): void;
}
