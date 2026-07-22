<?php

return [

    'completed' => 'Treatment completed.',
    'completed_with_followups' => 'Treatment completed. :created follow-up appointment(s) created, :skipped skipped due to conflicts.',

    'media' => [
        'uploaded' => 'File uploaded.',
        'deleted' => 'File deleted.',
    ],

    'status' => [
        'draft' => 'Draft',
        'completed' => 'Completed',
        'voided' => 'Voided',
    ],

    'payment' => [
        'method' => [
            'cash' => 'Cash',
            'card' => 'Card',
            'transfer' => 'Bank Transfer',
            'cheque' => 'Cheque',
        ],
    ],

    'case' => [
        'mode' => [
            'none' => 'No case link',
            'existing' => 'Link to open case',
            'new' => 'Create new case',
        ],
    ],

    'fields' => [
        'complaint' => 'Complaint',
        'diagnosis' => 'Diagnosis',
        'treatment_process' => 'Treatment Process',
        'notes' => 'Note',
    ],

    'report' => [
        'title' => 'Treatment Summary',
        'document_no' => 'Document No',
        'date' => 'Date',
        'patient' => 'Patient',
        'doctor' => 'Doctor',
        'services' => 'Services',
        'products' => 'Products',
        'line_item' => 'Description',
        'quantity' => 'Qty',
        'unit_price' => 'Unit Price',
        'discount' => 'Discount',
        'subtotal' => 'Subtotal',
        'total' => 'Total',
        'paid_total' => 'Paid',
        'remaining_balance' => 'Remaining Balance',
        'payments' => 'Payments',
        'payment_date' => 'Date',
        'payment_method' => 'Method',
        'payment_amount' => 'Amount',
        'no_payments' => 'No payments recorded.',
        'clinical_info' => 'Clinical Information',
    ],

    'follow_up' => [
        'mode' => [
            'none' => 'No follow-up',
            'single' => 'Single appointment',
            'package' => 'Package (multiple appointments)',
        ],
        'interval' => [
            'weekly' => 'Weekly',
            'biweekly' => 'Every 2 weeks',
            'monthly' => 'Monthly',
        ],
    ],

    'errors' => [
        'appointment_not_startable' => 'Cannot start a treatment for this appointment. Status must be Confirmed, Rescheduled, or Arrived.',
        'already_completed' => 'This treatment is already completed.',
        'case_not_found' => 'The selected case was not found or is no longer open.',
        'case_patient_mismatch' => 'The selected case does not belong to this patient.',
        'case_doctor_mismatch' => 'The selected case does not belong to this doctor.',
        'payment_not_allowed' => 'You do not have permission to record a payment.',
        'payments_exceed_total' => 'Payments cannot exceed the treatment total.',
        'case_create_not_allowed' => 'You do not have permission to create a new case.',
        'follow_up_not_allowed' => 'You do not have permission to create follow-up appointments.',
        'vertical_mismatch' => "The clinic's vertical is not compatible with this treatment type.",
        'media_delete_window_expired' => 'This file can no longer be deleted — the 48-hour correction window has passed.',
    ],

];
