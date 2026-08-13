<?php

use App\Modules\Reporting\Http\Controllers\ReportController;
use Illuminate\Support\Facades\Route;

// Single tabbed report page (finance + breakdowns), gated on reports.revenue.
Route::middleware('auth')->group(function () {
    Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/export', [ReportController::class, 'export'])->name('reports.export');
    Route::post('/reports/clear-cache', [ReportController::class, 'clearCache'])->name('reports.clear-cache');
});
