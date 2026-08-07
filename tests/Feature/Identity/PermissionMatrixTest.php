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

function pmRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('renders 5 role columns and every seeded permission exactly once, with correct role_ids', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pmRole($owner, 'owner', $clinic->id);

    $roleIdsByName = [];
    $flatPermissions = collect();

    $this->actingAs($owner)
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertInertia(function ($page) use (&$roleIdsByName, &$flatPermissions) {
            $page->has('roles', 5)
                ->where('roles', function ($roles) use (&$roleIdsByName) {
                    $roleIdsByName = collect($roles)->pluck('id', 'name')->all();

                    return true;
                })
                ->where('groups', function ($groups) use (&$flatPermissions) {
                    $flatPermissions = collect($groups)->flatMap(fn (array $g) => $g['permissions']);

                    return true;
                });
        });

    $seededPermissionNames = array_keys(
        (new ReflectionClass(PermissionSeeder::class))->getConstant('PERMISSIONS'),
    );

    expect($flatPermissions->pluck('name')->sort()->values()->all())
        ->toEqual(collect($seededPermissionNames)->sort()->values()->all());

    $clinicUpdate = $flatPermissions->firstWhere('name', 'clinic.update');

    expect($clinicUpdate['role_ids'])
        ->toContain($roleIdsByName['owner'], $roleIdsByName['manager'])
        ->not->toContain($roleIdsByName['doctor']);
});

it('403s for a role without roles.viewAny', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    pmRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('settings.roles.index'))
        ->assertForbidden();
});

it('reports is_customized true for a clinic that customized one of its baseline roles', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pmRole($owner, 'owner', $clinic->id);

    app(ClinicContext::class)->set($clinic->id);
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    $copy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);
    app(ClinicContext::class)->forget();

    $this->actingAs($owner)
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('roles', function ($roles) use ($copy) {
            $manager = collect($roles)->firstWhere('name', 'manager');

            return $manager['id'] === $copy->id && $manager['is_customized'] === true;
        }));
});

it("sets is_own true only for the viewing user's own column, and locked_role_ids on the protected permissions for it", function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pmRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertInertia(function ($page) {
            $page->where('roles', function ($roles) {
                $byName = collect($roles)->keyBy('name');

                return $byName['owner']['is_own'] === true
                    && $byName['manager']['is_own'] === false
                    && $byName['doctor']['is_own'] === false;
            })->where('groups', function ($groups) {
                $flat = collect($groups)->flatMap(fn (array $g) => $g['permissions']);
                $manage = $flat->firstWhere('name', 'roles.manage');
                $viewAny = $flat->firstWhere('name', 'roles.viewAny');
                $other = $flat->firstWhere('name', 'clinic.update');

                return $manage['locked_role_ids'] !== [] && $viewAny['locked_role_ids'] !== []
                    && $other['locked_role_ids'] === [];
            });
        });
});

it('reports can_delete false for baseline columns (global and customized copy alike)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pmRole($owner, 'owner', $clinic->id);

    app(ClinicContext::class)->set($clinic->id);
    $globalManager = Role::query()->where('name', 'manager')->whereNull('clinic_id')->firstOrFail();
    app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);
    app(ClinicContext::class)->forget();

    $this->actingAs($owner)
        ->get(route('settings.roles.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('roles', function ($roles) {
            return collect($roles)->every(fn (array $role) => $role['can_delete'] === false);
        }));
});
