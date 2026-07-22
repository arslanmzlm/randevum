<?php

return [

    'clinic_form_title' => 'Anamnez',
    'patient_form_title' => 'Sağlık Bilgilerim',
    'saved' => 'Anamnez kaydedildi.',

    'yes' => 'Evet',
    'no' => 'Hayır',

    'groups' => [
        'general' => 'Genel',
        'systemic' => 'Sistemik / Kronik',
        'allergy' => 'Alerji',
        'women' => 'Kadın',
        'podiatry' => 'Podoloji',
    ],

    'fields' => [
        'blood_type' => 'Kan Grubu',
        'height_cm' => 'Boy',
        'weight_kg' => 'Kilo',
        'smoking' => 'Sigara',
        'alcohol' => 'Alkol',
        'diabetes' => 'Diyabet',
        'hypertension' => 'Hipertansiyon',
        'cardiovascular' => 'Kalp-Damar Hastalığı',
        'blood_thinners' => 'Kan Sulandırıcı Kullanımı',
        'regular_medications' => 'Sürekli Kullanılan İlaçlar',
        'other_chronic' => 'Diğer Kronik Hastalık',
        'allergies' => 'Bilinen Alerjiler',
        'pregnancy' => 'Hamilelik / Emzirme',
        'foot_surgery_history' => 'Ayak Ameliyatı / Yaralanma Geçmişi',
        'diabetic_foot_history' => 'Diyabetik Ayak Öyküsü',
        'current_foot_complaint' => 'Mevcut Ayak Şikayeti',
    ],

    'options' => [
        'blood_type' => [
            'A+' => 'A Rh+',
            'A-' => 'A Rh-',
            'B+' => 'B Rh+',
            'B-' => 'B Rh-',
            'AB+' => 'AB Rh+',
            'AB-' => 'AB Rh-',
            '0+' => '0 Rh+',
            '0-' => '0 Rh-',
        ],
        'smoking' => [
            'none' => 'Kullanmıyor',
            'former' => 'Bırakmış',
            'active' => 'Kullanıyor',
        ],
        'alcohol' => [
            'none' => 'Kullanmıyor',
            'occasional' => 'Ara sıra',
            'regular' => 'Düzenli',
        ],
        'diabetes' => [
            'type1' => 'Tip 1',
            'type2' => 'Tip 2',
        ],
        'pregnancy' => [
            'pregnant' => 'Hamile',
            'breastfeeding' => 'Emziriyor',
        ],
    ],

    'units' => [
        'cm' => 'cm',
        'kg' => 'kg',
    ],

    'errors' => [
        'vertical_mismatch' => 'Kliniğin uzmanlık alanı bu anamnez türüyle uyumsuz.',
    ],

    'pdf' => [
        'title' => 'Anamnez Formu',
        'date' => 'Tarih',
        'patient' => 'Hasta',
        'empty' => 'Doldurulmuş anamnez kaydı bulunmuyor.',
    ],

];
