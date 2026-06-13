<?php

use App\Modules\Billing\Http\Controllers\PaymentController;
use Illuminate\Support\Facades\Route;

// Payment recording — standalone or treatment-bound.
Route::middleware('auth')->group(function () {
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
});
