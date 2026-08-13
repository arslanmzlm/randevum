<?php

namespace App\Enums;

enum TreatmentStatus: string
{
    case Draft = 'draft';
    case Completed = 'completed';
    case Voided = 'voided';
}
