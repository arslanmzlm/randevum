<?php

use App\Models\Clinic;
use App\Models\Role;
use App\Models\User;
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

function rnRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('renames a custom role', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rnRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $role = Role::query()->where('clinic_id', $clinic->id)->where('name', 'Muhasebeci')->firstOrFail();

    $this->actingAs($owner)
        ->put(route('settings.roles.update', $role), ['name' => 'Finans'])
        ->assertRedirect(route('settings.roles.index'));

    expect($role->fresh()->name)->toBe('Finans');
});

it('403s renaming a baseline copy', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rnRole($owner, 'owner', $clinic->id);

    app(ClinicContext::class)->set($clinic->id);
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $copy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);
    app(ClinicContext::class)->forget();

    $this->actingAs($owner)
        ->put(route('settings.roles.update', $copy), ['name' => 'Yeni Ad'])
        ->assertForbidden();

    expect($copy->fresh()->name)->toBe('manager');
});

it('rejects renaming a custom role to a reserved name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    rnRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $role = Role::query()->where('clinic_id', $clinic->id)->where('name', 'Muhasebeci')->firstOrFail();

    $this->actingAs($owner)
        ->put(route('settings.roles.update', $role), ['name' => 'manager'])
        ->assertInvalid(['name']);

    expect($role->fresh()->name)->toBe('Muhasebeci');
});

it("403s renaming another clinic's custom role", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    rnRole($ownerA, 'owner', $clinicA->id);
    rnRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $roleA = Role::query()->where('clinic_id', $clinicA->id)->where('name', 'Muhasebeci')->firstOrFail();

    $this->actingAs($ownerB)
        ->put(route('settings.roles.update', $roleA), ['name' => 'Finans'])
        ->assertForbidden();

    expect($roleA->fresh()->name)->toBe('Muhasebeci');
});
