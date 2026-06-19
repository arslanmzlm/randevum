<?php

namespace App\Enums;

enum TreatmentStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    // Voided is defined now; the treatment-voiding code path lands later.
    case Voided = 'voided';
}
