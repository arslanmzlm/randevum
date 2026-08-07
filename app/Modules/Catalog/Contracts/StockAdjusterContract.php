<?php

namespace App\Modules\Catalog\Contracts;

use App\Enums\StockMovementReason;
use App\Models\User;

/**
 * Atomic stock adjustment seam used by the Medical module on treatment completion/void.
 * Medical imports this contract; never the concrete StockMovementService.
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
}
