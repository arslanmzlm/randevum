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
}
