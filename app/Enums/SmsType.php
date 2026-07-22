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
    case InstallmentDue7d = 'installment_due_7d';
    case InstallmentDue1d = 'installment_due_1d';
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

    /**
     * Returns true for the types a clinic may override with a custom template.
     * BalanceReminder stays a fixed built-in message; OTP is platform-level.
     */
    public function isCustomizable(): bool
    {
        return match ($this) {
            self::AppointmentCreated, self::AppointmentCancelled, self::AppointmentRescheduled,
            self::Reminder24h, self::Reminder1h,
            self::InstallmentDue7d, self::InstallmentDue1d => true,
            self::BalanceReminder, self::Otp => false,
        };
    }

    /**
     * The customizable types — the set the template editor operates on.
     *
     * @return list<self>
     */
    public static function customizableCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $t) => $t->isCustomizable()));
    }

    /**
     * The lang key holding this type's default body. Both reminder types share
     * `sms.reminder.body`, and both installment-due types share `sms.installment.due.body`;
     * customizable types only (throws otherwise).
     */
    public function defaultBodyKey(): string
    {
        return match ($this) {
            self::AppointmentCreated => 'sms.appointment.created.body',
            self::AppointmentCancelled => 'sms.appointment.cancelled.body',
            self::AppointmentRescheduled => 'sms.appointment.rescheduled.body',
            self::Reminder24h, self::Reminder1h => 'sms.reminder.body',
            self::InstallmentDue7d, self::InstallmentDue1d => 'sms.installment.due.body',
            self::BalanceReminder, self::Otp => throw new \LogicException("SmsType {$this->value} has no default body key."),
        };
    }
}
