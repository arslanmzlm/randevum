<?php

use App\Modules\Core\Http\Controllers\ClinicController;
use Illuminate\Support\Facades\Route;

// Clinic profile (authenticated owner).
Route::middleware('auth')->group(function () {
    Route::get('/clinic', [ClinicController::class, 'edit'])->name('clinic.edit');
    Route::put('/clinic', [ClinicController::class, 'update'])->name('clinic.update');
    Route::post('/clinic/media/{collection}', [ClinicController::class, 'updateMedia'])->name('clinic.media.update');
    Route::delete('/clinic/media/{collection}', [ClinicController::class, 'removeMedia'])->name('clinic.media.remove');
});
