<?php

use App\Modules\Identity\Http\Controllers\RoleController;
use Illuminate\Support\Facades\Route;

// Permission matrix (read-only) — editing arrives in Dalga 8b.
Route::middleware('auth')->group(function (): void {
    Route::get('/settings/roles', [RoleController::class, 'index'])->name('settings.roles.index');
});
