<?php

use App\Modules\Core\Http\Controllers\ClinicController;
use App\Modules\Core\Http\Controllers\DoctorController;
use Illuminate\Support\Facades\Route;

// Clinic profile (authenticated owner).
Route::middleware('auth')->group(function () {
    Route::get('/clinic', [ClinicController::class, 'edit'])->name('clinic.edit');
    Route::put('/clinic', [ClinicController::class, 'update'])->name('clinic.update');
    Route::post('/clinic/media/{collection}', [ClinicController::class, 'updateMedia'])->name('clinic.media.update');
    Route::delete('/clinic/media/{collection}', [ClinicController::class, 'removeMedia'])->name('clinic.media.remove');
});

// Doctor management.
// Literal segments (create, me, self) are declared BEFORE {doctor} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/doctors', [DoctorController::class, 'index'])->name('doctors.index');
    Route::get('/doctors/create', [DoctorController::class, 'create'])->name('doctors.create');
    Route::get('/doctors/me', [DoctorController::class, 'mine'])->name('doctors.mine');
    Route::post('/doctors', [DoctorController::class, 'store'])->name('doctors.store');
    Route::post('/doctors/self', [DoctorController::class, 'storeOwn'])->name('doctors.storeOwn');
    Route::get('/doctors/{doctor}/edit', [DoctorController::class, 'edit'])->name('doctors.edit');
    Route::put('/doctors/{doctor}', [DoctorController::class, 'update'])->name('doctors.update');
    Route::delete('/doctors/{doctor}', [DoctorController::class, 'destroy'])->name('doctors.destroy');
    Route::post('/doctors/{doctor}/avatar', [DoctorController::class, 'updateAvatar'])->name('doctors.avatar.update');
    Route::delete('/doctors/{doctor}/avatar', [DoctorController::class, 'removeAvatar'])->name('doctors.avatar.remove');
});
