<?php

return [

    'created' => 'Case created.',
    'status_updated' => 'Case status updated.',
    'notes_updated' => 'Case notes updated.',
    'title_updated' => 'Case title updated.',
    'treatments_linked' => 'Treatments linked to the case.',
    'treatment_unlinked' => 'Treatment removed from the case.',

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
        'edit_window_expired' => 'The edit window has expired; only status changes are allowed.',
        'case_closed' => 'Treatments cannot be linked or unlinked on a closed case.',
        'treatment_not_found' => 'One or more selected treatments could not be found in this clinic.',
        'treatment_already_linked' => 'One of the selected treatments is already linked to a case.',
        'treatment_not_completed' => 'Only completed treatments can be linked to a case.',
        'treatment_patient_mismatch' => 'One of the selected treatments does not belong to this patient.',
        'treatment_doctor_mismatch' => 'One of the selected treatments does not belong to this case\'s doctor.',
        'treatments_mixed_doctors' => 'The selected treatments belong to different doctors and cannot be linked to the same case.',
        'treatment_not_linked' => 'This treatment is not linked to this case.',
        'doctor_required' => 'A doctor must be specified.',
        'doctor_not_own' => 'You can only create cases for your own doctor profile.',
    ],

];
