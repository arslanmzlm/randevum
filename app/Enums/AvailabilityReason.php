<?php

namespace App\Enums;

enum AvailabilityReason: string
{
    case OutsideHours = 'outside_hours';
    case ScheduleException = 'exception';
    case Conflict = 'conflict';
}
