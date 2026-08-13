<?php

namespace App\Modules\Catalog\Exceptions;

use App\Enums\StockMovementReason;
use RuntimeException;

/**
 * Thrown by the stock funnel when a movement's quantity contradicts its reason (a "stock in"
 * row that lowers stock, a "wastage" row that raises it). The manual dialog's FormRequest
 * catches the same conflict first and reports it as a field error, so reaching this
 * exception means either a non-form caller or a concurrent write that flipped the delta
 * between validation and the locked read.
 */
class StockMovementSignConflictException extends RuntimeException
{
    public function __construct(StockMovementReason $reason, int $delta)
    {
        $expected = $reason->sign() === 1 ? 'increase' : 'decrease';

        parent::__construct(
            "Stock movement reason [{$reason->value}] may only {$expected} stock, got a delta of {$delta}."
        );
    }
}
