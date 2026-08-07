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

    /**
     * @return array<int, self>
     */
    public static function breakdowns(): array
    {
        return array_values(array_filter(self::cases(), fn (self $tab): bool => ! $tab->isFinance()));
    }

    public function isFinance(): bool
    {
        return $this === self::Finance;
    }
}
