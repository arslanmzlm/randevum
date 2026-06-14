<?php

return [

    'title' => 'SMS Notifications',
    'description' => 'Manage which SMS notifications your clinic sends to patients.',

    'type' => [
        'appointment_created' => [
            'label' => 'Appointment created',
            'hint' => 'An SMS is sent to the patient when a new appointment is created.',
        ],
        'appointment_cancelled' => [
            'label' => 'Appointment cancelled',
            'hint' => 'An SMS is sent to the patient when an appointment is cancelled.',
        ],
        'appointment_rescheduled' => [
            'label' => 'Appointment rescheduled',
            'hint' => 'An SMS is sent to the patient when an appointment is moved to a new date.',
        ],
        'reminder_24h' => [
            'label' => '24-hour reminder',
            'hint' => 'A reminder SMS is sent 24 hours before the appointment.',
        ],
        'reminder_1h' => [
            'label' => '1-hour reminder',
            'hint' => 'A reminder SMS is sent 1 hour before the appointment.',
        ],
        'balance_reminder' => [
            'label' => 'Balance reminder',
            'hint' => 'An SMS is sent when the patient has a pending balance.',
        ],
    ],

    'nav' => 'SMS Notifications',

];
