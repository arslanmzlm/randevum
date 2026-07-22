<?php

return [

    'reminder' => [
        'body' => ':clinic: :date :time randevunuzu hatırlatırız.',
    ],

    'appointment' => [
        'created' => [
            'body' => ':clinic: :date :time için randevunuz oluşturuldu.',
        ],
        'cancelled' => [
            'body' => ':clinic: :date :time randevunuz iptal edildi.',
        ],
        'rescheduled' => [
            'body' => ':clinic: randevunuz :date :time olarak güncellendi.',
        ],
    ],

    'installment' => [
        'due' => [
            'body' => ':clinic: :amount tutarındaki taksitinizin son ödeme tarihi :date.',
        ],
    ],

];
