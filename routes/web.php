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
    Route::inertia('/settings', 'settings/Index')->name('settings');
});

// Domain route groups (auth/admin) are loaded from bootstrap/app.php.
