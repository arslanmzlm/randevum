<?php

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
        // TODO: reference AppointmentStatus enum once it exists; allowed set is provisional (decide later).
        'hard_delete_allowed_statuses' => ['confirmed'],
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

];
