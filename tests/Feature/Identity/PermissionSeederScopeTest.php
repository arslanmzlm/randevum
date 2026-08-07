<?php

use App\Models\Clinic;
use App\Models\Role;
use App\Modules\Identity\Services\RoleCustomizationService;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

it("re-seeding heals the global baseline but never touches a clinic's customized permission set", function (): void {
    $clinicA = Clinic::factory()->create();

    $globalManager = Role::query()->where('name', 'manager')->where('guard_name', 'web')->whereNull('clinic_id')->firstOrFail();

    app(ClinicContext::class)->set($clinicA->id);
    $copy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);

    // Owner strips a permission from the clinic's own copy (simulating 8b's editing UI).
    $copy->revokePermissionTo('doctors.create');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($copy->fresh()->permissions->pluck('name'))->not->toContain('doctors.create')
        ->and($globalManager->fresh()->permissions->pluck('name'))->toContain('doctors.create');

    $this->seed(PermissionSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect($copy->fresh()->permissions->pluck('name'))->not->toContain('doctors.create')
        ->and($globalManager->fresh()->permissions->pluck('name'))->toContain('doctors.create');
});
