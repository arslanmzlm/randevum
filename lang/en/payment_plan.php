<?php

return [

    'down_payment_note' => 'Down payment',

    'errors' => [
        'sum_mismatch' => 'Installment amounts plus the down payment must equal the total amount.',
        'sequence_invalid' => 'Installment sequences must be a contiguous 1..N matching the installment count.',
        'not_pending' => 'This installment has already been fully collected or cancelled.',
        'amount_exceeds_remaining' => 'Collection amount cannot exceed the installment\'s remaining amount.',
        'delete_after_collection' => 'A plan with a collected installment cannot be deleted; cancel it instead.',
    ],

];
