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

function rmtRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it("clinic B cannot submit clinic A's role id in the bulk permission save", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    rmtRole($ownerA, 'owner', $clinicA->id);
    rmtRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $customRoleA = Role::query()->where('clinic_id', $clinicA->id)->where('name', 'Muhasebeci')->firstOrFail();

    $this->actingAs($ownerB)
        ->put(route('settings.roles.permissions.update'), [
            'roles' => [['id' => $customRoleA->id, 'permissions' => ['services.create']]],
        ])
        ->assertInvalid(['roles.0.id']);

    expect(
        DB::table('role_has_permissions')->where('role_id', $customRoleA->id)->exists(),
    )->toBeFalse();
});

it("clinic B gets 403 deleting clinic A's custom role", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    rmtRole($ownerA, 'owner', $clinicA->id);
    rmtRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $customRoleA = Role::query()->where('clinic_id', $clinicA->id)->where('name', 'Muhasebeci')->firstOrFail();

    $this->actingAs($ownerB)
        ->delete(route('settings.roles.destroy', $customRoleA))
        ->assertForbidden();

    expect(Role::query()->whereKey($customRoleA->id)->exists())->toBeTrue();
});

it("clinic B never sees clinic A's custom role or copies as matrix columns", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    rmtRole($ownerA, 'owner', $clinicA->id);
    rmtRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)->post(route('settings.roles.store'), ['name' => 'Muhasebeci'])->assertRedirect();
    $customRoleA = Role::query()->where('clinic_id', $clinicA->id)->where('name', 'Muhasebeci')->firstOrFail();

    app(ClinicContext::class)->set($clinicA->id);
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $copyA = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);
    app(ClinicContext::class)->forget();

    $this->actingAs($ownerB)
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('roles', function ($roles) use ($customRoleA, $copyA) {
            $ids = collect($roles)->pluck('id');

            return ! $ids->contains($customRoleA->id) && ! $ids->contains($copyA->id);
        }));
});

it("clinic B's revert leaves clinic A's baseline copies intact", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    rmtRole($ownerA, 'owner', $clinicA->id);
    rmtRole($ownerB, 'owner', $clinicB->id);

    app(ClinicContext::class)->set($clinicA->id);
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $copyA = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);
    app(ClinicContext::class)->forget();

    $this->actingAs($ownerB)
        ->delete(route('settings.roles.revert'))
        ->assertRedirect(route('settings.roles.index'));

    expect(Role::query()->whereKey($copyA->id)->exists())->toBeTrue();
});
