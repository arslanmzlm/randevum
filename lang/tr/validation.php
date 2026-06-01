<?php

/*
|--------------------------------------------------------------------------
| Validation Language Lines (Turkish)
|--------------------------------------------------------------------------
|
| Common rules translated to Turkish. Missing keys fall back to the framework
| English messages (APP_FALLBACK_LOCALE=en), so this need not be exhaustive.
|
*/

return [
    'accepted' => ':attribute kabul edilmelidir.',
    'active_url' => ':attribute geçerli bir URL değil.',
    'after' => ':attribute :date tarihinden sonra olmalıdır.',
    'after_or_equal' => ':attribute :date tarihinden sonra veya ona eşit olmalıdır.',
    'alpha' => ':attribute yalnızca harf içerebilir.',
    'alpha_dash' => ':attribute yalnızca harf, rakam, tire ve alt çizgi içerebilir.',
    'alpha_num' => ':attribute yalnızca harf ve rakam içerebilir.',
    'array' => ':attribute bir dizi olmalıdır.',
    'before' => ':attribute :date tarihinden önce olmalıdır.',
    'before_or_equal' => ':attribute :date tarihinden önce veya ona eşit olmalıdır.',
    'between' => [
        'numeric' => ':attribute :min ile :max arasında olmalıdır.',
        'file' => ':attribute :min ile :max kilobayt arasında olmalıdır.',
        'string' => ':attribute :min ile :max karakter arasında olmalıdır.',
        'array' => ':attribute :min ile :max adet öğe içermelidir.',
    ],
    'boolean' => ':attribute alanı yalnızca doğru veya yanlış olabilir.',
    'confirmed' => ':attribute tekrarı eşleşmiyor.',
    'current_password' => 'Şifre hatalı.',
    'date' => ':attribute geçerli bir tarih değil.',
    'different' => ':attribute ile :other birbirinden farklı olmalıdır.',
    'digits' => ':attribute :digits haneli olmalıdır.',
    'digits_between' => ':attribute :min ile :max hane arasında olmalıdır.',
    'email' => ':attribute geçerli bir e-posta adresi olmalıdır.',
    'exists' => 'Seçilen :attribute geçersiz.',
    'file' => ':attribute bir dosya olmalıdır.',
    'filled' => ':attribute alanı doldurulmalıdır.',
    'image' => ':attribute bir görsel olmalıdır.',
    'in' => 'Seçilen :attribute geçersiz.',
    'integer' => ':attribute bir tam sayı olmalıdır.',
    'max' => [
        'numeric' => ':attribute en fazla :max olabilir.',
        'file' => ':attribute en fazla :max kilobayt olabilir.',
        'string' => ':attribute en fazla :max karakter olabilir.',
        'array' => ':attribute en fazla :max öğe içerebilir.',
    ],
    'mimes' => ':attribute şu türlerden bir dosya olmalıdır: :values.',
    'min' => [
        'numeric' => ':attribute en az :min olmalıdır.',
        'file' => ':attribute en az :min kilobayt olmalıdır.',
        'string' => ':attribute en az :min karakter olmalıdır.',
        'array' => ':attribute en az :min öğe içermelidir.',
    ],
    'not_in' => 'Seçilen :attribute geçersiz.',
    'numeric' => ':attribute bir sayı olmalıdır.',
    'phone' => ':attribute geçerli bir telefon numarası olmalıdır.',
    'present' => ':attribute alanı mevcut olmalıdır.',
    'regex' => ':attribute biçimi geçersiz.',
    'required' => ':attribute alanı zorunludur.',
    'required_if' => ':other :value olduğunda :attribute alanı zorunludur.',
    'required_with' => ':values mevcut olduğunda :attribute alanı zorunludur.',
    'same' => ':attribute ile :other eşleşmelidir.',
    'size' => [
        'numeric' => ':attribute :size olmalıdır.',
        'file' => ':attribute :size kilobayt olmalıdır.',
        'string' => ':attribute :size karakter olmalıdır.',
        'array' => ':attribute :size öğe içermelidir.',
    ],
    'string' => ':attribute bir metin olmalıdır.',
    'unique' => ':attribute zaten kullanılıyor.',
    'url' => ':attribute geçerli bir URL olmalıdır.',
    'uuid' => ':attribute geçerli bir UUID olmalıdır.',

    'attributes' => [
        'email' => 'E-posta adresi',
        'password' => 'Şifre',
        'password_confirmation' => 'Şifre tekrarı',
        'current_password' => 'Mevcut şifre',
        'phone' => 'Telefon numarası',
        'code' => 'Doğrulama kodu',
        'first_name' => 'Ad',
        'last_name' => 'Soyad',
        'clinic_name' => 'Klinik adı',
        'vertical_id' => 'Klinik türü',
        'terms' => 'Kullanım koşulları',
        // clinic profile
        'name' => 'Klinik adı',
        'slug' => 'URL adresi',
        'description' => 'Açıklama',
        'website' => 'Web sitesi',
        'country_id' => 'Ülke',
        'city_id' => 'Şehir',
        'district' => 'İlçe',
        'address' => 'Adres',
        'postal_code' => 'Posta kodu',
        'default_slot_duration_minutes' => 'Varsayılan randevu süresi',
        'working_hours' => 'Çalışma saatleri',
        'image' => 'Görsel',
        // service catalog
        'price' => 'Fiyat',
        'default_complaint' => 'Varsayılan şikayet',
        'default_diagnosis' => 'Varsayılan tanı',
        'default_treatment_process' => 'Varsayılan tedavi süreci',
        // product catalog
        'brand' => 'Marka',
        'category' => 'Kategori',
        'sku' => 'Stok kodu',
        'unit' => 'Birim',
        'current_stock' => 'Mevcut stok',
        // patient record
        'contact_phone' => 'Acil telefon numarası',
        'birth_date' => 'Doğum tarihi',
        'gender' => 'Cinsiyet',
        'notification_enabled' => 'SMS bildirimleri',
        'is_legacy' => 'Sistem öncesi hasta',
        'notes' => 'Notlar',
        // doctor profile
        'title' => 'Ünvan',
        'specialization' => 'Uzmanlık',
        'bio' => 'Biyografi',
        'license_number' => 'Lisans numarası',
        'certificate' => 'Sertifikalar',
        'is_active' => 'Aktif/Pasif durumu',
    ],

    'working_hours_closed_conflict' => ':attribute kapalı olarak işaretlenmiş günlerde açılış/kapanış saati belirtilemez.',
    'working_hours_open_close_required' => ':attribute için açılış ve kapanış saati zorunludur.',
    'working_hours_time_format' => ':attribute için geçerli bir saat formatı giriniz (SS:dd).',
    'working_hours_close_after_open' => ':attribute kapanış saati açılış saatinden sonra olmalıdır.',
    'working_hours_break_format' => ':attribute mola başlangıç ve bitiş saatini içermelidir.',
    'working_hours_break_end_after_start' => ':attribute mola bitiş saati başlangıçtan sonra olmalıdır.',
    'working_hours_break_within_hours' => ':attribute mola saatleri çalışma saatleri içinde olmalıdır.',

    'custom' => [],
];
