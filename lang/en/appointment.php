<?php

return [

    'title' => 'Create Appointment',
    'created' => 'Appointment for :patient at :time created successfully.',

    'fields' => [
        'patient' => 'Patient',
        'doctor' => 'Doctor',
        'service' => 'Service',
        'starts_at' => 'Appointment date & time',
        'duration_minutes' => 'Duration (minutes)',
        'is_walk_in' => 'Walk-in patient',
    ],

    'hints' => [
        'service' => 'Duration is set automatically when a service is selected.',
        'duration_minutes' => 'Leave blank to use the service or clinic default.',
        'is_walk_in' => 'Walk-in patients bypass schedule exceptions and conflict checks; working hours still apply.',
    ],

    'walk_in_label' => 'Walk-in (no appointment)',

    'new_patient_link' => 'Add new patient',

    'errors' => [
        'outside_hours' => 'The selected time is outside the clinic working hours or falls within a break.',
        'exception' => 'The doctor has a scheduled leave or closure at the selected time.',
        'conflict' => 'The doctor already has an appointment at the selected time.',
        'doctor_not_allowed' => 'You can only create appointments for yourself.',
        'phone_trashed' => 'This phone number belongs to a deleted patient. Restore them from the patients screen.',
        'not_reschedulable' => 'This appointment cannot be rescheduled.',
        'not_cancellable' => 'This appointment cannot be cancelled.',
        'not_arrivable' => 'This appointment cannot be marked as arrived. Status must be Confirmed or Rescheduled.',
        'not_completable' => 'This appointment cannot be marked as completed. Status must be Arrived.',
        'not_no_showable' => 'This appointment cannot be marked as no-show. Status must be Confirmed or Rescheduled.',
        'delete_not_allowed' => 'This appointment cannot be deleted. Please cancel it instead.',
    ],

    'rescheduled' => 'Appointment rescheduled successfully.',
    'cancelled' => 'Appointment cancelled.',
    'deleted' => 'Appointment deleted.',
    'checked_in' => 'Patient checked in.',
    'marked_no_show' => 'Appointment marked as no-show.',
    'reminder_sent' => 'Reminder SMS sent.',
    'quota_full' => 'Monthly SMS quota is full. Reminder could not be sent.',

    'submit' => 'Create Appointment',

    'availability' => [
        'checking' => 'Checking availability…',
        'available' => 'This slot is available.',
        'unavailable' => 'This slot is not available.',
    ],

    'day_schedule' => [
        'title' => "Doctor's Day",
        'empty' => 'No appointments for this day.',
        'loading' => 'Loading appointments…',
        'walk_in' => 'Walk-in',
        'status' => [
            'pending' => 'Pending',
            'confirmed' => 'Confirmed',
            'rescheduled' => 'Rescheduled',
            'arrived' => 'Arrived',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'no_show' => 'No-show',
        ],
    ],

];
