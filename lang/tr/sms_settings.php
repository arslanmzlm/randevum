<?php

return [

    'title' => 'SMS Bildirimleri',
    'description' => 'Kliniğinizin müşterilerine hangi SMS bildirimlerini göndereceğini yönetin.',

    'type' => [
        'appointment_created' => [
            'label' => 'Randevu oluşturuldu',
            'hint' => 'Yeni randevu oluşturulduğunda hastaya SMS gönderilir.',
        ],
        'appointment_cancelled' => [
            'label' => 'Randevu iptal edildi',
            'hint' => 'Randevu iptal edildiğinde hastaya SMS gönderilir.',
        ],
        'appointment_rescheduled' => [
            'label' => 'Randevu yeniden planlandı',
            'hint' => 'Randevu başka bir tarihe taşındığında hastaya SMS gönderilir.',
        ],
        'reminder_24h' => [
            'label' => '24 saat hatırlatma',
            'hint' => 'Randevudan 24 saat önce hatırlatma SMS\'i gönderilir.',
        ],
        'reminder_1h' => [
            'label' => '1 saat hatırlatma',
            'hint' => 'Randevudan 1 saat önce hatırlatma SMS\'i gönderilir.',
        ],
        'balance_reminder' => [
            'label' => 'Bakiye hatırlatma',
            'hint' => 'Hastanın bekleyen bakiyesi olduğunda SMS gönderilir.',
        ],
    ],

    'nav' => 'SMS Bildirimleri',

    // Static illustrative values for the template editor's live preview —
    // never a real patient/appointment lookup.
    'preview_sample' => [
        'date' => '15 Ağustos 2026',
        'time' => '14:30',
        'patient' => 'Ayşe Yılmaz',
        'doctor' => 'Dr. Mehmet Demir',
    ],

];
