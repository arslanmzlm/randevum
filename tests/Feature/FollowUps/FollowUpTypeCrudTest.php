<?php

use App\Models\Clinic;
use App\Models\FollowUpType;
use App\Models\User;
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

/**
 * Assign a clinic-scoped role (follow-up-type CRUD tests).
 */
function ftcRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array<string, mixed>
 */
function ftcPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Kontrol araması',
        'is_active' => true,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /follow-up-types — followUpTypes.manage gates the whole screen
// ---------------------------------------------------------------------------

it('owner can access the follow-up-types index', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('follow-up-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('follow-up-types/Index')->has('followUpTypes'));
});

it('doctor (lacks followUpTypes.manage) gets 403 on the index', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    ftcRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('follow-up-types.index'))
        ->assertForbidden();
});

it('index lists only the active clinic\'s types', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinicA->id);

    FollowUpType::factory()->create(['clinic_id' => $clinicA->id, 'name' => 'A Türü']);
    FollowUpType::factory()->create(['clinic_id' => $clinicB->id, 'name' => 'B Türü']);

    $this->actingAs($owner)
        ->get(route('follow-up-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('followUpTypes.data', 1)
            ->where('followUpTypes.data.0.name', 'A Türü')
        );
});

// ---------------------------------------------------------------------------
// POST /follow-up-types — store
// ---------------------------------------------------------------------------

it('owner can create a clinic follow-up type', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('follow-up-types.store'), ftcPayload(['name' => 'Yeni Tür']))
        ->assertRedirect(route('follow-up-types.index'));

    $type = FollowUpType::withoutGlobalScopes()->where('name', 'Yeni Tür')->first();
    expect($type)->not->toBeNull()
        ->and($type->clinic_id)->toBe($clinic->id)
        ->and($type->is_system)->toBeFalse();
});

it('manager can create a follow-up type', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    ftcRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('follow-up-types.store'), ftcPayload(['name' => 'Manager Type']))
        ->assertRedirect();

    expect(FollowUpType::withoutGlobalScopes()->where('name', 'Manager Type')->exists())->toBeTrue();
});

it('doctor gets 403 creating a follow-up type', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    ftcRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('follow-up-types.store'), ftcPayload())
        ->assertForbidden();
});

it('is_system is never mass-assignable from the request', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('follow-up-types.store'), ftcPayload(['name' => 'Injected', 'is_system' => true]));

    expect(FollowUpType::withoutGlobalScopes()->where('name', 'Injected')->first()->is_system)->toBeFalse();
});

it('rejects a missing name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('follow-up-types.store'), ftcPayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

it('rejects a duplicate name case-insensitively within the same clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    FollowUpType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Kontrol']);

    $this->actingAs($owner)
        ->post(route('follow-up-types.store'), ftcPayload(['name' => 'kontrol']))
        ->assertSessionHasErrors('name');
});

it('allows reusing the name of a soft-deleted type', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $old = FollowUpType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Eski Tür']);
    $old->delete();

    $this->actingAs($owner)
        ->post(route('follow-up-types.store'), ftcPayload(['name' => 'Eski Tür']))
        ->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------------------
// PUT /follow-up-types/{followUpType} — update
// ---------------------------------------------------------------------------

it('owner can update a follow-up type', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $type = FollowUpType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Old']);

    $this->actingAs($owner)
        ->put(route('follow-up-types.update', $type), ftcPayload(['name' => 'New', 'is_active' => false]))
        ->assertRedirect(route('follow-up-types.index'));

    $fresh = $type->fresh();
    expect($fresh->name)->toBe('New')
        ->and($fresh->is_active)->toBeFalse();
});

it('deactivating a system type succeeds (is_active toggle works)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $system = FollowUpType::factory()->system()->create(['clinic_id' => $clinic->id, 'name' => 'Kontrol araması']);

    $this->actingAs($owner)
        ->put(route('follow-up-types.update', $system), ftcPayload(['name' => 'Kontrol araması', 'is_active' => false]))
        ->assertRedirect(route('follow-up-types.index'));

    expect($system->fresh()->is_active)->toBeFalse();
});

it('update rejects a duplicate name case-insensitively, ignoring self', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    FollowUpType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Diğer Tür']);
    $type = FollowUpType::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Bu Tür']);

    // Renaming to itself (case-insensitive) must not error.
    $this->actingAs($owner)
        ->put(route('follow-up-types.update', $type), ftcPayload(['name' => 'bu tür']))
        ->assertSessionHasNoErrors();

    // Renaming to another type's name must error.
    $this->actingAs($owner)
        ->put(route('follow-up-types.update', $type), ftcPayload(['name' => 'diğer tür']))
        ->assertSessionHasErrors('name');
});

// ---------------------------------------------------------------------------
// DELETE /follow-up-types/{followUpType} — clinic rows soft-delete, system rows never
// ---------------------------------------------------------------------------

it('owner can soft-delete a clinic-created follow-up type', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $type = FollowUpType::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->delete(route('follow-up-types.destroy', $type))
        ->assertRedirect(route('follow-up-types.index'));

    expect(FollowUpType::withoutGlobalScopes()->find($type->id)->deleted_at)->not->toBeNull();
});

it('deleting a system type fails with a validation error and leaves it intact', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ftcRole($owner, 'owner', $clinic->id);

    $system = FollowUpType::factory()->system()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->delete(route('follow-up-types.destroy', $system))
        ->assertSessionHasErrors();

    expect(FollowUpType::withoutGlobalScopes()->find($system->id)->deleted_at)->toBeNull();
});

it('doctor gets 403 deleting a follow-up type', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    ftcRole($doctorUser, 'doctor', $clinic->id);

    $type = FollowUpType::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($doctorUser)
        ->delete(route('follow-up-types.destroy', $type))
        ->assertForbidden();
});
