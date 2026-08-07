<?php

use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\RolePermissionController;
use Illuminate\Support\Facades\Route;

// Permission matrix + role management (editable matrix, custom roles, revert-to-defaults).
Route::middleware('auth')->group(function (): void {
    Route::get('/settings/roles', [RoleController::class, 'index'])->name('settings.roles.index');
    Route::post('/settings/roles', [RoleController::class, 'store'])->name('settings.roles.store');
    Route::put('/settings/roles/permissions', [RolePermissionController::class, 'update'])->name('settings.roles.permissions.update');
    Route::delete('/settings/roles/customizations', [RoleController::class, 'revert'])->name('settings.roles.revert');
    Route::delete('/settings/roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('settings.roles.destroy');
});
