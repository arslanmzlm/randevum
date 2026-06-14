<?php

namespace App\Enums;

enum SmsType: string
{
    case AppointmentCreated = 'appointment_created';
    case AppointmentCancelled = 'appointment_cancelled';
    case AppointmentRescheduled = 'appointment_rescheduled';
    case Reminder24h = 'reminder_24h';
    case Reminder1h = 'reminder_1h';
    case BalanceReminder = 'balance_reminder';
    case Otp = 'otp';

    /**
     * Returns true for every type that is subject to the per-clinic gate.
     * OTP is a platform-level send and is never toggleable by a clinic.
     */
    public function isClinicScoped(): bool
    {
        return $this !== self::Otp;
    }

    /**
     * All clinic-scoped types — the set the gate and settings UI operate on.
     *
     * @return list<self>
     */
    public static function clinicScopedCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isClinicScoped()));
    }
}
