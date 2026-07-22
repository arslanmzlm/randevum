<?php

return [

    'completed' => 'Tedavi tamamlandı.',
    'completed_with_followups' => 'Tedavi tamamlandı. :created randevu oluşturuldu, :skipped çakışma nedeniyle atlandı.',

    'media' => [
        'uploaded' => 'Dosya yüklendi.',
        'deleted' => 'Dosya silindi.',
    ],

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

    'fields' => [
        'complaint' => 'Şikayet',
        'diagnosis' => 'Tanı',
        'treatment_process' => 'Tedavi Süreci',
        'notes' => 'Not',
    ],

    'report' => [
        'title' => 'Tedavi Özeti',
        'document_no' => 'Belge No',
        'date' => 'Tarih',
        'patient' => 'Hasta',
        'doctor' => 'Doktor',
        'services' => 'Hizmetler',
        'products' => 'Ürünler',
        'line_item' => 'Açıklama',
        'quantity' => 'Adet',
        'unit_price' => 'Birim Fiyat',
        'discount' => 'İndirim',
        'subtotal' => 'Ara Toplam',
        'total' => 'Toplam',
        'paid_total' => 'Ödenen',
        'remaining_balance' => 'Kalan Bakiye',
        'payments' => 'Ödemeler',
        'payment_date' => 'Tarih',
        'payment_method' => 'Yöntem',
        'payment_amount' => 'Tutar',
        'no_payments' => 'Ödeme kaydı yok.',
        'clinical_info' => 'Tedavi Bilgileri',
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
        'media_delete_window_expired' => 'Bu dosya 48 saatlik düzeltme süresi geçtiği için silinemez.',
    ],

];
