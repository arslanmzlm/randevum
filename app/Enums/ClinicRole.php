<?php

namespace App\Enums;

enum ClinicRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Doctor = 'doctor';
    case Receptionist = 'receptionist';
    case Assistant = 'assistant';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $case): string => $case->value, self::cases());
    }
}
