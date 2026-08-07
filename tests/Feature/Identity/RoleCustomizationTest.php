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

/**
 * Assign a clinic-scoped Spatie Teams role to a user (mirrors AppointmentTypeTenantIsolationTest's atIsoRole).
 */
function rcRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

function rcGlobalRole(string $name): Role
{
    return Role::query()->where('name', $name)->where('guard_name', 'web')->whereNull('clinic_id')->firstOrFail();
}

it('creates a clinic copy with an identical permission set and moves only that clinic\'s assignments', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $userA = User::factory()->create();
    $userB = User::factory()->create();

    rcRole($userA, 'manager', $clinicA->id);
    rcRole($userB, 'manager', $clinicB->id);

    $globalManager = rcGlobalRole('manager');
    $globalPermissionNames = $globalManager->permissions->pluck('name')->all();

    app(ClinicContext::class)->set($clinicA->id);
    $copy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);

    expect($copy->clinic_id)->toBe($clinicA->id)
        ->and($copy->id)->not->toBe($globalManager->id)
        ->and($copy->permissions->pluck('name')->all())->toEqualCanonicalizing($globalPermissionNames);

    $userAAssignment = DB::table('model_has_roles')
        ->where('model_id', $userA->id)->where('model_type', $userA->getMorphClass())
        ->where('clinic_id', $clinicA->id)->value('role_id');
    expect($userAAssignment)->toBe($copy->id);

    // Clinic B's assignment to the SAME global role name must be untouched.
    $userBAssignment = DB::table('model_has_roles')
        ->where('model_id', $userB->id)->where('model_type', $userB->getMorphClass())
        ->where('clinic_id', $clinicB->id)->value('role_id');
    expect($userBAssignment)->toBe($globalManager->id);
});

it('is idempotent: customizing an already-owned copy returns the same row without a new insert', function (): void {
    $clinicA = Clinic::factory()->create();
    $globalManager = rcGlobalRole('manager');

    app(ClinicContext::class)->set($clinicA->id);
    $service = app(RoleCustomizationService::class);

    $copy = $service->customizeForActiveClinic($globalManager);
    $copyCountAfterFirst = Role::query()->where('name', 'manager')->where('clinic_id', $clinicA->id)->count();

    $copyAgain = $service->customizeForActiveClinic($copy);
    $copyCountAfterSecond = Role::query()->where('name', 'manager')->where('clinic_id', $clinicA->id)->count();

    expect($copyAgain->id)->toBe($copy->id)
        ->and($copyCountAfterFirst)->toBe(1)
        ->and($copyCountAfterSecond)->toBe(1);
});

it("flushes the permission cache so an affected user's can() reflects the copy immediately", function (): void {
    $clinicA = Clinic::factory()->create();
    $userA = User::factory()->create();
    rcRole($userA, 'manager', $clinicA->id);

    $globalManager = rcGlobalRole('manager');

    app(ClinicContext::class)->set($clinicA->id);
    app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicA->id);
    $userA->unsetRelation('roles')->unsetRelation('permissions');

    expect($userA->can('clinic.update'))->toBeTrue();
});

it('rejects customizing a platform-global role', function (): void {
    $clinicA = Clinic::factory()->create();
    $superadmin = rcGlobalRole('superadmin');

    app(ClinicContext::class)->set($clinicA->id);

    expect(fn () => app(RoleCustomizationService::class)->customizeForActiveClinic($superadmin))
        ->toThrow(RuntimeException::class);
});

it("rejects customizing another clinic's role row", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $globalManager = rcGlobalRole('manager');

    app(ClinicContext::class)->set($clinicB->id);
    $clinicBCopy = app(RoleCustomizationService::class)->customizeForActiveClinic($globalManager);

    app(ClinicContext::class)->set($clinicA->id);

    expect(fn () => app(RoleCustomizationService::class)->customizeForActiveClinic($clinicBCopy))
        ->toThrow(RuntimeException::class);
});
