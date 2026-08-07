<?php

use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
use App\Modules\Identity\Services\RoleCustomizationService;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

function rrRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('reverting deletes every baseline copy, re-points assignments, and leaves custom roles alone', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    $manager = User::factory()->create();
    $ownerB = User::factory()->create();
    rrRole($owner, 'owner', $clinicA->id);
    rrRole($manager, 'manager', $clinicA->id);
    rrRole($ownerB, 'owner', $clinicB->id);

    app(ClinicContext::class)->set($clinicA->id);
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $managerCopy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);
    app(ClinicContext::class)->forget();

    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $customRole = Role::query()->where('clinic_id', $clinicA->id)->where('name', 'Muhasebeci')->firstOrFail();

    // Clinic B also customizes 'manager' — its copy must survive clinic A's revert.
    app(ClinicContext::class)->set($clinicB->id);
    $globalManagerAgain = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $clinicBCopy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManagerAgain);
    app(ClinicContext::class)->forget();

    $this->actingAs($owner)
        ->delete(route('settings.roles.revert'))
        ->assertRedirect(route('settings.roles.index'));

    expect(Role::query()->whereKey($managerCopy->id)->exists())->toBeFalse()
        ->and(Role::query()->whereKey($customRole->id)->exists())->toBeTrue()
        ->and(Role::query()->whereKey($clinicBCopy->id)->exists())->toBeTrue();

    $managerAssignment = DB::table('model_has_roles')
        ->where('model_id', $manager->id)->where('model_type', $manager->getMorphClass())
        ->where('clinic_id', $clinicA->id)->value('role_id');
    expect($managerAssignment)->toBe($globalManager->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicA->id);
    $manager->unsetRelation('roles')->unsetRelation('permissions');
    expect($manager->can('clinic.update'))->toBeTrue();
});

it('reverting with nothing customized is a no-op redirect, not an error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rrRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->delete(route('settings.roles.revert'))
        ->assertRedirect(route('settings.roles.index'))
        ->assertSessionHasNoErrors();
});
