<?php

namespace App\Enums;

enum ReportTab: string
{
    case Finance = 'finance';
    case Doctor = 'doctor';
    case Service = 'service';
    case Product = 'product';
    case AppointmentType = 'appointment_type';
    case ExpenseOwner = 'expense_owner';

    public function isFinance(): bool
    {
        return $this === self::Finance;
    }
}
