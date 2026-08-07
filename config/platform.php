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
    | Medical media
    |--------------------------------------------------------------------------
    |
    | Treatment file/photo uploads (private disk). delete_window is the 48h
    | "wrong file uploaded" hard-delete correction affordance — after it, delete
    | is blocked (full KVKK retention lifecycle is a later feature, not this one).
    |
    */

    'media' => [
        'max_file_size' => (int) env('PLATFORM_MEDIA_MAX_FILE_SIZE', 50 * 1024 * 1024),
        'delete_window' => (int) env('PLATFORM_MEDIA_DELETE_WINDOW', 48 * 3600),
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
    | SMS quota & templates
    |--------------------------------------------------------------------------
    |
    | Monthly clinic-scoped SMS allowance when a clinic sets no per-clinic
    | override (clinics.sms_monthly_quota is null). OTP sends are never counted.
    | max_segments caps a custom template's encoding-aware segment count
    | (GSM-7 vs UCS-2) at save time — protects SMS cost/quota.
    |
    */

    'sms' => [
        'monthly_quota' => (int) env('PLATFORM_SMS_MONTHLY_QUOTA', 1000),
        'max_segments' => (int) env('PLATFORM_SMS_MAX_SEGMENTS', 3),
    ],

    'otp' => [
        'length' => (int) env('PLATFORM_OTP_LENGTH', 6),
        'ttl' => (int) env('PLATFORM_OTP_TTL', 300),
        'max_attempts' => (int) env('PLATFORM_OTP_MAX_ATTEMPTS', 5),
        'resend_throttle' => (int) env('PLATFORM_OTP_RESEND_THROTTLE', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Map
    |--------------------------------------------------------------------------
    |
    | Where the location picker opens when a clinic has no saved coordinates,
    | and the zoom it snaps to once a point is chosen. Never inline a country
    | centre in the frontend — a second country only changes env here.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Follow-ups
    |--------------------------------------------------------------------------
    |
    | Default follow-up types provisioned for every clinic (new registration, and
    | idempotently for existing ones via FollowUpTypeSeeder). Type names are clinic
    | DATA (editable rows), not lang keys — same as the appointment-types precedent.
    |
    */

    'follow_ups' => [
        'default_types' => [
            'Kontrol araması',
            'Tedavi takibi',
            'Ödeme hatırlatması',
            'Randevu hatırlatması',
            'Memnuniyet araması',
        ],
    ],

    'map' => [
        'default_center' => [
            'lat' => (float) env('PLATFORM_MAP_DEFAULT_LAT', 39.0),
            'lng' => (float) env('PLATFORM_MAP_DEFAULT_LNG', 35.0),
        ],
        'default_zoom' => (int) env('PLATFORM_MAP_DEFAULT_ZOOM', 6),
        'selected_zoom' => (int) env('PLATFORM_MAP_SELECTED_ZOOM', 15),
    ],

];
