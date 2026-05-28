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

];
