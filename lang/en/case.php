<?php

return [

    'created' => 'Case created.',
    'status_updated' => 'Case status updated.',
    'notes_updated' => 'Case notes updated.',
    'follow_up_updated' => 'Follow-up details updated.',
    'follow_up_cleared' => 'Follow-up removed.',
    'title_updated' => 'Case title updated.',
    'treatments_linked' => 'Treatments linked to the case.',

    'status' => [
        'open' => 'Open',
        'suspended' => 'Suspended',
        'follow_up' => 'Follow-up',
        'closed' => 'Closed',
    ],

    'actions' => [
        'close' => 'Close Case',
        'reopen' => 'Reopen Case',
        'suspend' => 'Suspend',
        'follow_up' => 'Mark Follow-up',
    ],

    'errors' => [
        'transition_not_allowed' => 'This status transition is not allowed for this case.',
        'follow_up_date_required' => 'A follow-up date is required when setting status to Follow-up.',
        'edit_window_expired' => 'The edit window has expired; only status changes are allowed.',
        'case_not_open' => 'Treatments can only be linked to an open case.',
        'treatment_not_found' => 'One or more selected treatments could not be found in this clinic.',
        'treatment_already_linked' => 'One of the selected treatments is already linked to a case.',
        'treatment_not_completed' => 'Only completed treatments can be linked to a case.',
        'treatment_patient_mismatch' => 'One of the selected treatments does not belong to this patient.',
        'treatment_doctor_mismatch' => 'One of the selected treatments does not belong to this case\'s doctor.',
        'treatments_mixed_doctors' => 'The selected treatments belong to different doctors and cannot be linked to the same case.',
        'doctor_required' => 'A doctor must be specified.',
        'doctor_not_own' => 'You can only create cases for your own doctor profile.',
    ],

];
