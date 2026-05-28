<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin routes (authenticated)
|--------------------------------------------------------------------------
| Platform admin surface. Horizon / Pulse dashboards mount here too (admin-only).
*/
Route::middleware('auth')->group(function () {
    Route::inertia('/admin', 'Admin/Index')->name('admin');
});
