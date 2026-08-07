<?php

namespace App\Enums;

enum FollowUpStatus: string
{
    case Open = 'open';
    case Done = 'done';
    case Cancelled = 'cancelled';
}
