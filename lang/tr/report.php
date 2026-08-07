<?php

return [
    'title' => 'Raporlar',
    'total' => 'Toplam',
    'unspecified' => 'Belirtilmemiş',

    'tabs' => [
        'finance' => 'Finans',
        'doctor' => 'Doktor',
        'service' => 'Hizmet',
        'product' => 'Ürün',
        'appointment_type' => 'Randevu Türü',
        'expense_owner' => 'Gider Sahibi',
    ],

    'columns' => [
        'label' => 'Ad',
        'amount' => 'Tutar',
        'count' => 'Adet',
        'average' => 'Ortalama',
        'appointment_count' => 'Randevu Adedi',
        'cancelled_count' => 'İptal',
        'no_show_count' => 'Gelmedi',
        'cancelled_rate' => 'İptal Oranı (%)',
        'no_show_rate' => 'Gelmedi Oranı (%)',
    ],

    // Per-tab overrides for the shared label/amount/count columns: the same cell means a
    // different thing per tab (hizmet sekmesinde tahsilat değil satılan tutar). Mirrors
    // resources/js/locales/tr.ts so the sheet matches the screen; falls back to `columns`.
    'label_header' => [
        'doctor' => 'Doktor',
        'service' => 'Hizmet',
        'product' => 'Ürün',
        'appointment_type' => 'Randevu Türü',
        'expense_owner' => 'Kullanıcı',
    ],

    'amount_header' => [
        'doctor' => 'Tahsilat',
        'service' => 'Satış Tutarı',
        'product' => 'Satış Tutarı',
        'appointment_type' => 'Tahsilat',
        'expense_owner' => 'Toplam Gider',
    ],

    'count_header' => [
        'doctor' => 'Tamamlanan Tedavi',
        'service' => 'Satılan Adet',
        'product' => 'Satılan Adet',
        'appointment_type' => 'Randevu Adedi',
        'expense_owner' => 'Gider Adedi',
    ],

    'finance' => [
        'item' => 'Kalem',
        'amount' => 'Tutar',
        'revenue' => 'Gelir',
        'expense' => 'Gider',
        'net' => 'Net',
        'by_method' => 'Ödeme Yöntemine Göre',
        'by_period' => 'Döneme Göre',
        'manual_by_category' => 'Manuel Gelir (Kategori)',
        'expense_by_category' => 'Gider Kırılımı (Kategori)',
    ],
];
