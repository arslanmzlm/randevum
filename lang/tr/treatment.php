<?php

return [

    'completed' => 'Tedavi tamamlandı.',
    'completed_with_followups' => 'Tedavi tamamlandı. :created randevu oluşturuldu, :skipped çakışma nedeniyle atlandı.',

    'status' => [
        'draft' => 'Taslak',
        'completed' => 'Tamamlandı',
        'voided' => 'İptal Edildi',
    ],

    'payment' => [
        'method' => [
            'cash' => 'Nakit',
            'card' => 'Kart',
            'transfer' => 'Havale/EFT',
            'cheque' => 'Çek',
        ],
    ],

    'case' => [
        'mode' => [
            'none' => 'Vakaya bağlama',
            'existing' => 'Açık vakadan seç',
            'new' => 'Yeni vaka oluştur',
        ],
    ],

    'follow_up' => [
        'mode' => [
            'none' => 'Takip randevusu yok',
            'single' => 'Tek randevu',
            'package' => 'Paket (çoklu randevu)',
        ],
        'interval' => [
            'weekly' => 'Haftalık',
            'biweekly' => '2 Haftada bir',
            'monthly' => 'Aylık',
        ],
    ],

    'errors' => [
        'appointment_not_startable' => 'Bu randevu için tedavi başlatılamaz. Randevunun durumu Onaylandı, Yeniden Planlandı veya Geldi olmalıdır.',
        'already_completed' => 'Bu tedavi zaten tamamlanmış.',
        'case_not_found' => 'Seçilen vaka bulunamadı veya artık açık değil.',
        'case_patient_mismatch' => 'Seçilen vaka bu hastaya ait değil.',
        'case_doctor_mismatch' => 'Seçilen vaka bu doktora ait değil.',
        'payment_not_allowed' => 'Ödeme kaydetme yetkiniz bulunmamaktadır.',
        'payments_exceed_total' => 'Ödemelerin toplamı tedavi tutarını aşamaz.',
        'case_create_not_allowed' => 'Yeni vaka oluşturma yetkiniz bulunmamaktadır.',
        'follow_up_not_allowed' => 'Takip randevusu oluşturma yetkiniz bulunmamaktadır.',
        'vertical_mismatch' => 'Kliniğin uzmanlık alanı bu tedavi türüyle uyumsuz.',
    ],

];
