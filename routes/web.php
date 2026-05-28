<?php

use App\Modules\Identity\Http\Controllers\Auth\OtpLoginController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

/*
|--------------------------------------------------------------------------
| OTP login endpoints (guest only)
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::post('login/otp/request', [OtpLoginController::class, 'request'])
        ->middleware('throttle:otp-request')
        ->name('login.otp.request');

    Route::post('login/otp/verify', [OtpLoginController::class, 'verify'])
        ->middleware('throttle:otp-verify')
        ->name('login.otp.verify');
});

/*
|--------------------------------------------------------------------------
| Authenticated pages
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::inertia('/dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('/admin', 'Admin/Index')->name('admin');
});
