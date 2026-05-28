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

    'otp' => [
        'sms_body' => 'Your Randevum login code is: :code. The code is valid for :ttl seconds.',
        'invalid_code' => 'The code you entered is incorrect, has expired, or too many attempts have been made.',
    ],

    'register' => [
        'title' => 'Create Account',
        'subtitle' => 'Register your clinic in a few steps.',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email address',
        'password' => 'Password',
        'password_confirmation' => 'Confirm password',
        'vertical' => 'Clinic type',
        'clinic_name' => 'Clinic name',
        'terms_label' => 'Terms of service and privacy policy',
        'terms_agree' => 'I have read and accept the :terms.',
        'submit' => 'Create Account',
        'have_account' => 'Already have an account?',
        'login_link' => 'Sign in',
    ],

];
