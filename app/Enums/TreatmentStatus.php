<?php

namespace App\Enums;

enum TreatmentStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    // Voided ships as an enum case now; the code path is implemented in 1.28.
    case Voided = 'voided';
}
