<?php

return [

    'created' => 'Follow-up created.',
    'completed' => 'Follow-up completed.',
    'cancelled' => 'Follow-up cancelled.',

    'status' => [
        'open' => 'Open',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ],

    'errors' => [
        'patient_not_found' => 'The selected patient could not be found in this clinic.',
        'case_not_found' => 'The selected case could not be found in this clinic.',
        'case_patient_mismatch' => 'The selected case does not belong to this patient.',
        'not_open' => 'This follow-up has already been completed or cancelled.',
        'due_date_required' => 'A follow-up date is required when setting status to Follow-up.',
    ],

];
