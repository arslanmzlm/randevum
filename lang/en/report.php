<?php

return [
    'title' => 'Reports',
    'total' => 'Total',
    'unspecified' => 'Unspecified',

    'tabs' => [
        'finance' => 'Finance',
        'doctor' => 'Doctor',
        'service' => 'Service',
        'product' => 'Product',
        'appointment_type' => 'Appointment type',
        'expense_owner' => 'Expense owner',
        'branch' => 'Branch',
    ],

    'columns' => [
        'label' => 'Name',
        'amount' => 'Amount',
        'count' => 'Count',
        'average' => 'Average',
        'appointment_count' => 'Appointment count',
        'cancelled_count' => 'Cancelled',
        'no_show_count' => 'No-show',
        'cancelled_rate' => 'Cancellation rate (%)',
        'no_show_rate' => 'No-show rate (%)',
        'expense' => 'Expense',
        'net' => 'Net',
    ],

    // Per-tab overrides for the shared label/amount/count columns: the same cell means a
    // different thing per tab (the service tab reports sold value, not collected money).
    // Mirrors resources/js/locales/en.ts; falls back to `columns`.
    'label_header' => [
        'doctor' => 'Doctor',
        'service' => 'Service',
        'product' => 'Product',
        'appointment_type' => 'Appointment type',
        'expense_owner' => 'User',
        'branch' => 'Branch',
    ],

    'amount_header' => [
        'doctor' => 'Collected',
        'service' => 'Sold value',
        'product' => 'Sold value',
        'appointment_type' => 'Collected',
        'expense_owner' => 'Total expense',
        'branch' => 'Collected',
    ],

    'count_header' => [
        'doctor' => 'Completed treatments',
        'service' => 'Units sold',
        'product' => 'Units sold',
        'appointment_type' => 'Appointments',
        'expense_owner' => 'Expense count',
        'branch' => 'Transaction count',
    ],

    'finance' => [
        'item' => 'Item',
        'amount' => 'Amount',
        'revenue' => 'Revenue',
        'expense' => 'Expense',
        'net' => 'Net',
        'by_method' => 'By payment method',
        'by_period' => 'By period',
        'manual_by_category' => 'Manual income (category)',
        'expense_by_category' => 'Expense breakdown (category)',
    ],
];
