<?php

use App\Modules\Billing\Http\Controllers\ExpenseController;
use App\Modules\Billing\Http\Controllers\FinanceController;
use App\Modules\Billing\Http\Controllers\PaymentController;
use App\Modules\Billing\Http\Controllers\PaymentPlanController;
use App\Modules\Billing\Http\Controllers\RefundController;
use Illuminate\Support\Facades\Route;

// Payment recording — standalone or treatment-bound.
Route::middleware('auth')->group(function () {
    Route::post('/payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::post('/transactions/{transaction}/refund', [RefundController::class, 'store'])->name('transactions.refund');
    // Taksit planı (payment plans) — collections screen + create/collect/remind/cancel/delete.
    Route::get('/payment-plans/installments', [PaymentPlanController::class, 'index'])->name('payment-plans.installments');
    Route::post('/payment-plans', [PaymentPlanController::class, 'store'])->name('payment-plans.store');
    Route::post('/payment-plans/installments/{installment}/collect', [PaymentPlanController::class, 'collect'])->name('payment-plans.installments.collect');
    Route::post('/payment-plans/installments/{installment}/remind', [PaymentPlanController::class, 'sendReminder'])->name('payment-plans.installments.remind');
    Route::post('/payment-plans/{paymentPlan}/cancel', [PaymentPlanController::class, 'cancel'])->name('payment-plans.cancel');
    Route::delete('/payment-plans/{paymentPlan}', [PaymentPlanController::class, 'destroy'])->name('payment-plans.destroy');
    // Unified finance overview (revenue + expense + net) — owner/manager, gated by reports.revenue.
    Route::get('/reports/finance', [FinanceController::class, 'index'])->name('reports.finance');
    Route::post('/reports/finance/clear-cache', [FinanceController::class, 'clearCache'])->name('reports.finance.clear');
    // Giderlerim — own-expenses self-service, every clinic role (gated by expenses.create).
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->name('expenses.destroy');
});
