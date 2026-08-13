<?php

namespace App\Enums;

/**
 * How a manual stock adjustment states its intent: as a movement (a reason plus a positive
 * quantity, the sign coming from the reason) or as a count (the new total, the delta being
 * whatever closes the gap).
 */
enum StockAdjustmentMode: string
{
    case Movement = 'movement';
    case Count = 'count';
}
