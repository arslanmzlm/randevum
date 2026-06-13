<?php

use App\Modules\Billing\Http\Controllers\PaymentController;
use App\Modules\Billing\Http\Controllers\RefundController;
use Illuminate\Support\Facades\Route;

// Payment recording — standalone or treatment-bound.
Route::middleware('auth')->group(function () {
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::post('/transactions/{transaction}/refund', [RefundController::class, 'store'])->name('transactions.refund');
});
