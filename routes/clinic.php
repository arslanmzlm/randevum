<?php

use App\Modules\Catalog\Http\Controllers\ProductController;
use App\Modules\Catalog\Http\Controllers\ServiceController;
use App\Modules\Core\Http\Controllers\ClinicController;
use App\Modules\Core\Http\Controllers\DoctorController;
use App\Modules\Medical\Http\Controllers\PatientController;
use App\Modules\Scheduling\Http\Controllers\AppointmentController;
use App\Modules\Scheduling\Http\Controllers\ScheduleExceptionController;
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

// Patient records.
// Literal segments (create) are declared BEFORE {patient} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/patients', [PatientController::class, 'index'])->name('patients.index');
    Route::get('/patients/create', [PatientController::class, 'create'])->name('patients.create');
    Route::get('/patients/search', [PatientController::class, 'search'])->middleware('throttle:60,1')->name('patients.search');
    Route::post('/patients', [PatientController::class, 'store'])->name('patients.store');
    Route::get('/patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
    Route::get('/patients/{patient}/edit', [PatientController::class, 'edit'])->name('patients.edit');
    Route::put('/patients/{patient}', [PatientController::class, 'update'])->name('patients.update');
    Route::delete('/patients/{patient}', [PatientController::class, 'destroy'])->name('patients.destroy');
    Route::patch('/patients/{patient}/notes', [PatientController::class, 'updateNotes'])->name('patients.notes.update');
    Route::post('/patients/{patient}/restore', [PatientController::class, 'restore'])->name('patients.restore');
});

// Service catalog.
// Literal segments (create) are declared BEFORE {service} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/services', [ServiceController::class, 'index'])->name('services.index');
    Route::get('/services/create', [ServiceController::class, 'create'])->name('services.create');
    Route::post('/services', [ServiceController::class, 'store'])->name('services.store');
    Route::get('/services/{service}/edit', [ServiceController::class, 'edit'])->name('services.edit');
    Route::put('/services/{service}', [ServiceController::class, 'update'])->name('services.update');
    Route::delete('/services/{service}', [ServiceController::class, 'destroy'])->name('services.destroy');
});

// Appointments — manual/walk-in creation.
// Literal 'create' is declared BEFORE any future {appointment} segment.
Route::middleware('auth')->group(function () {
    Route::get('/appointments/create', [AppointmentController::class, 'create'])->name('appointments.create');
    Route::post('/appointments', [AppointmentController::class, 'store'])->name('appointments.store');
});

// Doctor availability / schedule exceptions.
Route::middleware('auth')->group(function () {
    Route::get('/schedule-exceptions', [ScheduleExceptionController::class, 'index'])->name('schedule-exceptions.index');
    Route::post('/schedule-exceptions', [ScheduleExceptionController::class, 'store'])->name('schedule-exceptions.store');
    Route::delete('/schedule-exceptions/{scheduleException}', [ScheduleExceptionController::class, 'destroy'])->name('schedule-exceptions.destroy');
});

// Product catalog.
// Literal segments (create) are declared BEFORE {product} so they are
// not captured as a route-model-bound id.
Route::middleware('auth')->group(function () {
    Route::get('/products', [ProductController::class, 'index'])->name('products.index');
    Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
    Route::post('/products', [ProductController::class, 'store'])->name('products.store');
    Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->name('products.edit');
    Route::put('/products/{product}', [ProductController::class, 'update'])->name('products.update');
    Route::patch('/products/{product}/stock', [ProductController::class, 'updateStock'])->name('products.stock.update');
    Route::delete('/products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
});
