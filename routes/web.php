<?php

use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

/*
|--------------------------------------------------------------------------
| Authenticated app pages
|--------------------------------------------------------------------------
*/
Route::middleware('auth')->group(function () {
    Route::inertia('/dashboard', 'Dashboard')->name('dashboard');
    Route::inertia('/account', 'account/Index')->name('account');
});

// Domain route groups (auth/admin/clinic) are loaded from bootstrap/app.php.
