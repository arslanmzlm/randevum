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

function rmtiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it("clinic A's customized role copy never appears in clinic B's matrix", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    rmtiRole($ownerA, 'owner', $clinicA->id);
    rmtiRole($ownerB, 'owner', $clinicB->id);

    app(ClinicContext::class)->set($clinicA->id);
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $copy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);
    app(ClinicContext::class)->forget();

    $this->actingAs($ownerB)
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertInertia(function ($page) use ($copy) {
            $page->where('roles', function ($roles) use ($copy) {
                $manager = collect($roles)->firstWhere('name', 'manager');

                return $manager['id'] !== $copy->id && $manager['is_customized'] === false;
            })->where('groups', function ($groups) use ($copy) {
                $allRoleIds = collect($groups)
                    ->flatMap(fn (array $g) => $g['permissions'])
                    ->flatMap(fn (array $p) => $p['role_ids']);

                return ! $allRoleIds->contains($copy->id);
            });
        });

    $this->actingAs($ownerA)
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('roles', function ($roles) use ($copy) {
            $manager = collect($roles)->firstWhere('name', 'manager');

            return $manager['id'] === $copy->id && $manager['is_customized'] === true;
        }));
});
