<?php

namespace App\Enums;

enum SmokingStatus: string
{
    case None = 'none';
    case Former = 'former';
    case Occasional = 'occasional';
    case Regular = 'regular';
}
