<?php

namespace App\Enums;

enum PaymentPlanStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
