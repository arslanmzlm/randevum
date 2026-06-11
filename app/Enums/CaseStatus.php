<?php

namespace App\Enums;

enum CaseStatus: string
{
    case Open = 'open';
    case Suspended = 'suspended';
    case FollowUp = 'follow_up';
    case Closed = 'closed';
}
