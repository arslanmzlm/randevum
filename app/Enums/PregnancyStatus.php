<?php

namespace App\Enums;

enum PregnancyStatus: string
{
    case None = 'none';
    case Pregnant = 'pregnant';
    case Breastfeeding = 'breastfeeding';
}
