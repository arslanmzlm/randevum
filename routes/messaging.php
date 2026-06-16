<?php

use App\Modules\Messaging\Http\Controllers\ClinicSmsSettingController;
use App\Modules\Messaging\Http\Controllers\SmsLogController;
use Illuminate\Support\Facades\Route;

// Clinic SMS settings — per-type send toggle.
Route::middleware('auth')->group(function () {
    Route::get('/clinic/sms-settings', [ClinicSmsSettingController::class, 'edit'])->name('clinic.sms-settings.edit');
    Route::put('/clinic/sms-settings', [ClinicSmsSettingController::class, 'update'])->name('clinic.sms-settings.update');
});

// SMS log — clinic-wide read-only list.
Route::middleware('auth')->group(function () {
    Route::get('/sms-logs', [SmsLogController::class, 'index'])->name('sms-logs.index');
});
