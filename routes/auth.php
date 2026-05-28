<?php

use App\Modules\Identity\Http\Controllers\Auth\OtpLoginController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\RegisteredUserController;

/*
|--------------------------------------------------------------------------
| Authentication routes (guest only)
|--------------------------------------------------------------------------
| Email+password login and the register view come from Fortify. These add the
| OTP login endpoints and override Fortify's POST /register to apply a throttle
| (Fortify ships it unthrottled). Loaded after Fortify's routes, so the register
| override wins the method+URI lookup.
*/
Route::middleware('guest')->group(function () {
    Route::post('login/otp/request', [OtpLoginController::class, 'request'])
        ->middleware('throttle:otp-request')
        ->name('login.otp.request');

    Route::post('login/otp/verify', [OtpLoginController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('login.otp.verify');

    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:register')
        ->name('register.store');
});
