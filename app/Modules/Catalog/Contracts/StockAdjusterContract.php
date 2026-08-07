<?php

namespace App\Modules\Catalog\Contracts;

use App\Enums\StockMovementReason;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\User;

/**
 * Stock ledger seam for other modules: Medical adjusts on treatment completion/void, a
 * vertical's product seeder writes the opening balance. They import this contract; never
 * the concrete StockMovementService.
 */
interface StockAdjusterContract
{
    /**
     * Atomically adjust a product's current_stock by $delta (positive = restock, negative = deduct)
     * and record a stock_movements row for it. Stock may go negative — no floor guard is applied here.
     */
    public function adjust(
        int $productId,
        int $delta,
        StockMovementReason $reason,
        ?int $treatmentId = null,
        ?string $note = null,
        ?User $actor = null,
    ): void;

    /**
     * Record the opening `initial` movement for a product whose row already carries the stock
     * value, so balance_after equals $quantity verbatim (adjust() would apply it a second time).
     */
    public function recordInitial(Product $product, int $quantity, ?User $actor = null): StockMovement;
}
