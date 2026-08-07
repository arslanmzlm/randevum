<?php

return [

    'errors' => [
        'not_refundable' => 'This transaction cannot be refunded.',
        'amount_exceeds_remaining' => 'Refund amount cannot exceed the remaining refundable amount.',
        'delete_window_passed' => 'This record can no longer be deleted; the delete window has passed.',
        'not_manual_income' => 'This record is a patient payment; it cannot be deleted as manual income.',
    ],

];
