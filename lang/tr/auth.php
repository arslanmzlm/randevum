<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Language Lines
    |--------------------------------------------------------------------------
    |
    | Server-side strings for authentication and OTP flows. Mirror frontend
    | keys in resources/js/i18n.ts under the `auth` namespace.
    |
    */

    'failed' => 'Bu kimlik bilgileri kayıtlarımızla eşleşmiyor.',
    'password' => 'Girilen şifre hatalı.',
    'throttle' => 'Çok fazla giriş denemesi. Lütfen :seconds saniye sonra tekrar deneyin.',

    'otp' => [
        'sms_body' => 'Randevum giriş kodunuz: :code. Kod :ttl saniye geçerlidir.',
        'invalid_code' => 'Girdiğiniz kod hatalı, süresi dolmuş veya çok fazla deneme yapıldı.',
    ],

    'register' => [
        'title' => 'Hesap Oluştur',
        'subtitle' => 'Kliniğinizi birkaç adımda kayıt edin.',
        'first_name' => 'Ad',
        'last_name' => 'Soyad',
        'email' => 'E-posta adresi',
        'password' => 'Şifre',
        'password_confirmation' => 'Şifre tekrarı',
        'vertical' => 'Klinik türü',
        'clinic_name' => 'Klinik adı',
        'terms_label' => 'Kullanım koşulları ve gizlilik politikası',
        'terms_agree' => ':terms\'i okudum ve kabul ediyorum.',
        'submit' => 'Hesap Oluştur',
        'have_account' => 'Zaten hesabınız var mı?',
        'login_link' => 'Giriş yapın',
        'welcome' => 'Hesabınız oluşturuldu. Aramıza hoş geldiniz!',
    ],

];
