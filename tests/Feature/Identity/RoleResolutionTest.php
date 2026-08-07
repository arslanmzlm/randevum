<?php

use App\Models\Clinic;
use App\Models\Role;
use App\Modules\Core\Services\RoleResolver;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * A global `manager` role (from RoleSeeder) plus a clinic-A copy of it, same name.
 *
 * @return array{0: Clinic, 1: Role, 2: Role}
 */
function roleResolutionFixture(): array
{
    $clinicA = Clinic::factory()->create();
    $globalManager = Role::query()->where('name', 'manager')->where('guard_name', 'web')->whereNull('clinic_id')->firstOrFail();

    // Role::query()->create(), not Role::create() — bypasses Spatie's own duplicate-name
    // check, exactly like RoleCustomizationService does.
    $clinicManager = Role::query()->create([
        'name' => 'manager',
        'guard_name' => 'web',
        'clinic_id' => $clinicA->id,
    ]);

    return [$clinicA, $globalManager, $clinicManager];
}

it("resolves clinic A's copy when both a global and a clinic-A row share the name", function (): void {
    [$clinicA, $globalManager, $clinicManager] = roleResolutionFixture();

    $resolved = app(RoleResolver::class)->resolveForClinic($clinicA->id, 'manager');

    expect($resolved->id)->toBe($clinicManager->id);
});

it('resolves the global row for a clinic with no copy of its own', function (): void {
    [$clinicA, $globalManager, $clinicManager] = roleResolutionFixture();
    $clinicB = Clinic::factory()->create();

    $resolved = app(RoleResolver::class)->resolveForClinic($clinicB->id, 'manager');

    expect($resolved->id)->toBe($globalManager->id);
});

it('resolves the global row when no clinic is in context', function (): void {
    [$clinicA, $globalManager, $clinicManager] = roleResolutionFixture();

    $resolved = app(RoleResolver::class)->resolveForClinic(null, 'manager');

    expect($resolved->id)->toBe($globalManager->id);
});

it('throws ModelNotFoundException for an unknown role name', function (): void {
    expect(fn () => app(RoleResolver::class)->resolveForClinic(null, 'no-such-role'))
        ->toThrow(ModelNotFoundException::class);
});

it('resolves the clinic copy deterministically across repeated calls', function (): void {
    [$clinicA, $globalManager, $clinicManager] = roleResolutionFixture();

    $resolver = app(RoleResolver::class);

    for ($i = 0; $i < 10; $i++) {
        expect($resolver->resolveForClinic($clinicA->id, 'manager')->id)->toBe($clinicManager->id);
    }
});

it('resolveManyForClinic keeps the clinic-first row per name, not the last query match', function (): void {
    [$clinicA, $globalManager, $clinicManager] = roleResolutionFixture();

    $resolved = app(RoleResolver::class)->resolveManyForClinic($clinicA->id, ['manager', 'doctor']);

    expect($resolved->get('manager')->id)->toBe($clinicManager->id)
        ->and($resolved->get('doctor'))->not->toBeNull()
        ->and($resolved->get('doctor')->clinic_id)->toBeNull();
});
