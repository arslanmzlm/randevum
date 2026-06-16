<?php

use App\Enums\AppointmentStatus;

return [

    /*
    |--------------------------------------------------------------------------
    | Edit / delete windows
    |--------------------------------------------------------------------------
    |
    | Time limits (in seconds) the Service layer reads on every edit/delete.
    | After the window a treatment/case edit becomes a correction record and a
    | transaction can only be reversed by a counter-entry. Env-overridable so
    | tests (and ops) can shrink them without touching code.
    |
    */

    'edit_windows' => [
        'treatment' => (int) env('PLATFORM_EDIT_WINDOW_TREATMENT', 48 * 3600),
        'case' => (int) env('PLATFORM_EDIT_WINDOW_CASE', 48 * 3600),
        'transaction_delete' => (int) env('PLATFORM_EDIT_WINDOW_TRANSACTION_DELETE', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment
    |--------------------------------------------------------------------------
    |
    | Statuses an appointment may be hard-deleted from (only while the start
    | time has not passed). Any other status transitions to Cancelled instead.
    |
    */

    'appointment' => [
        'hard_delete_allowed_statuses' => [AppointmentStatus::Confirmed->value],
        'upcoming_widget_limit' => (int) env('PLATFORM_UPCOMING_WIDGET_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Appointment reminders
    |--------------------------------------------------------------------------
    |
    | Reminder SMS fire at each offset (minutes before start); the 5-min
    | scheduler matches appointments within +/- window_minutes of each offset.
    |
    */

    'reminders' => [
        'offsets' => [24 * 60, 60], // minutes before appointment start
        'window_minutes' => (int) env('PLATFORM_REMINDER_WINDOW_MINUTES', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | OTP (phone-based passwordless login)
    |--------------------------------------------------------------------------
    |
    | Settings for the SMS one-time password login flow. All durations are in
    | seconds. Read exclusively through the Service layer — never inline.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | SMS quota
    |--------------------------------------------------------------------------
    |
    | Monthly clinic-scoped SMS allowance when a clinic sets no per-clinic
    | override (clinics.sms_monthly_quota is null). OTP sends are never counted.
    |
    */

    'sms' => [
        'monthly_quota' => (int) env('PLATFORM_SMS_MONTHLY_QUOTA', 1000),
    ],

    'otp' => [
        'length' => (int) env('PLATFORM_OTP_LENGTH', 6),
        'ttl' => (int) env('PLATFORM_OTP_TTL', 300),
        'max_attempts' => (int) env('PLATFORM_OTP_MAX_ATTEMPTS', 5),
        'resend_throttle' => (int) env('PLATFORM_OTP_RESEND_THROTTLE', 60),
    ],

];
