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

function crRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('creates a custom role with zero permissions, visible as an is_custom column', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    crRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])
        ->assertRedirect(route('settings.roles.index'));

    $role = Role::query()->where('clinic_id', $clinic->id)->where('name', 'Muhasebeci')->firstOrFail();
    expect($role->permissions)->toBeEmpty();

    $this->actingAs($owner)
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('roles', function ($roles) use ($role) {
            $column = collect($roles)->firstWhere('id', $role->id);

            return $column !== null && $column['is_custom'] === true && $column['is_customized'] === false;
        }));
});

it('rejects a duplicate custom role name case-insensitively', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    crRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();

    $this->actingAs($owner)
        ->post(route('settings.roles.store'), ['name' => 'muhasebeci'])
        ->assertInvalid(['name']);

    expect(Role::query()->where('clinic_id', $clinic->id)->where('name', 'muhasebeci')->exists())->toBeFalse();
});

it('rejects a custom role name that collides with a global role name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    crRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('settings.roles.store'), ['name' => 'manager'])
        ->assertInvalid(['name']);

    expect(Role::query()->where('clinic_id', $clinic->id)->where('name', 'manager')->exists())->toBeFalse();
});

it('grants permissions to a custom role through the bulk save', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    crRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $role = Role::query()->where('clinic_id', $clinic->id)->where('name', 'Muhasebeci')->firstOrFail();

    $this->actingAs($owner)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [['id' => $role->id, 'permissions' => ['services.create']]],
        ])
        ->assertRedirect();

    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['services.create']);
});

it('deletes an unassigned custom role', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    crRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $role = Role::query()->where('clinic_id', $clinic->id)->where('name', 'Muhasebeci')->firstOrFail();

    $this->actingAs($owner)
        ->delete(route('settings.roles.destroy', $role))
        ->assertRedirect(route('settings.roles.index'));

    expect(Role::query()->whereKey($role->id)->exists())->toBeFalse();
});

it('422s deleting a custom role that has an assigned user', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $staff = User::factory()->create();
    crRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $role = Role::query()->where('clinic_id', $clinic->id)->where('name', 'Muhasebeci')->firstOrFail();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $staff->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($owner)
        ->delete(route('settings.roles.destroy', $role))
        ->assertInvalid(['role']);

    expect(Role::query()->whereKey($role->id)->exists())->toBeTrue();
});

it('403s deleting a baseline copy', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    crRole($owner, 'owner', $clinic->id);

    app(ClinicContext::class)->set($clinic->id);
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $copy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);
    app(ClinicContext::class)->forget();

    $this->actingAs($owner)
        ->delete(route('settings.roles.destroy', $copy))
        ->assertForbidden();

    expect(Role::query()->whereKey($copy->id)->exists())->toBeTrue();
});

it("403s deleting another clinic's custom role", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    crRole($ownerA, 'owner', $clinicA->id);
    crRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $roleA = Role::query()->where('clinic_id', $clinicA->id)->where('name', 'Muhasebeci')->firstOrFail();

    $this->actingAs($ownerB)
        ->delete(route('settings.roles.destroy', $roleA))
        ->assertForbidden();

    expect(Role::query()->whereKey($roleA->id)->exists())->toBeTrue();
});
