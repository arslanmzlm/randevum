<?php

use App\Modules\Billing\Http\Controllers\PaymentController;
use App\Modules\Billing\Http\Controllers\RefundController;
use App\Modules\Billing\Http\Controllers\RevenueController;
use Illuminate\Support\Facades\Route;

// Payment recording — standalone or treatment-bound.
Route::middleware('auth')->group(function () {
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::post('/transactions/{transaction}/refund', [RefundController::class, 'store'])->name('transactions.refund');
    Route::get('/reports/revenue', [RevenueController::class, 'index'])->name('reports.revenue');
    Route::post('/reports/revenue/clear-cache', [RevenueController::class, 'clearCache'])->name('reports.revenue.clear');
});
