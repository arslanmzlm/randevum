<?php

namespace App\Enums;

enum StockMovementReason: string
{
    case TreatmentUsage = 'treatment_usage';
    case ManualAdjustment = 'manual_adjustment';
    case Return = 'return';
    case Initial = 'initial';
}
