<?php

use App\Modules\Messaging\Http\Controllers\ClinicSmsSettingController;
use Illuminate\Support\Facades\Route;

// Clinic SMS settings — per-type send toggle.
Route::middleware('auth')->group(function () {
    Route::get('/clinic/sms-settings', [ClinicSmsSettingController::class, 'edit'])->name('clinic.sms-settings.edit');
    Route::put('/clinic/sms-settings', [ClinicSmsSettingController::class, 'update'])->name('clinic.sms-settings.update');
});
