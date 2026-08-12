<?php

use App\Modules\Identity\Http\Controllers\BranchController;
use App\Modules\Identity\Http\Controllers\ClinicSwitchController;
use App\Modules\Identity\Http\Controllers\RoleController;
use App\Modules\Identity\Http\Controllers\RolePermissionController;
use Illuminate\Support\Facades\Route;

// Permission matrix + role management (editable matrix, custom roles, revert-to-defaults).
Route::middleware('auth')->group(function (): void {
    Route::get('/settings/roles', [RoleController::class, 'index'])->name('settings.roles.index');
    Route::post('/settings/roles', [RoleController::class, 'store'])->name('settings.roles.store');
    Route::put('/settings/roles/permissions', [RolePermissionController::class, 'update'])->name('settings.roles.permissions.update');
    Route::delete('/settings/roles/customizations', [RoleController::class, 'revert'])->name('settings.roles.revert');
    Route::put('/settings/roles/{role}', [RoleController::class, 'update'])->whereNumber('role')->name('settings.roles.update');
    Route::delete('/settings/roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role')->name('settings.roles.destroy');
});

// Multi-branch: switching the active clinic + opening a new branch under the tenant.
Route::middleware('auth')->group(function (): void {
    Route::post('/clinics/switch', [ClinicSwitchController::class, 'store'])->name('clinics.switch');
    Route::get('/settings/branches', [BranchController::class, 'index'])->name('settings.branches.index');
    Route::get('/settings/branches/create', [BranchController::class, 'create'])->name('settings.branches.create');
    Route::post('/settings/branches', [BranchController::class, 'store'])->name('settings.branches.store');
});
