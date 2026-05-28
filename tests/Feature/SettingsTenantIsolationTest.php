<?php

use App\Models\Clinic;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role to a user (Spatie Teams: clinic_id on assignment).
 */
function settingsIsoClinicRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// GET /settings — auth.user data isolation
// ---------------------------------------------------------------------------

it("GET /settings exposes owner-A's own email in auth.user", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create(['email' => 'ownera@example.com']);
    $ownerB = User::factory()->create(['email' => 'ownerb@example.com']);

    settingsIsoClinicRole($ownerA, 'owner', $clinicA->id);
    settingsIsoClinicRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)
        ->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('settings/Index')
            ->where('auth.user.email', 'ownera@example.com')
        );
});

it("GET /settings for owner-A never exposes owner-B's email in auth.user", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create(['email' => 'ownera@example.com']);
    $ownerB = User::factory()->create(['email' => 'ownerb@example.com']);

    settingsIsoClinicRole($ownerA, 'owner', $clinicA->id);
    settingsIsoClinicRole($ownerB, 'owner', $clinicB->id);

    // Owner-A sees their own email; confirming B's email is absent from A's view
    $this->actingAs($ownerA)
        ->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', 'ownera@example.com')
        );

    // Owner-B sees their own email independently
    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($ownerB)
        ->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('auth.user.email', 'ownerb@example.com')
        );
});

// ---------------------------------------------------------------------------
// PUT /user/profile-information — cross-user isolation
// ---------------------------------------------------------------------------

it('profile update by owner-A only affects owner-A, not owner-B', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create([
        'first_name' => 'Alice',
        'last_name' => 'A',
        'email' => 'ownera@example.com',
    ]);
    $ownerB = User::factory()->create([
        'first_name' => 'Bob',
        'last_name' => 'B',
        'email' => 'ownerb@example.com',
    ]);

    settingsIsoClinicRole($ownerA, 'owner', $clinicA->id);
    settingsIsoClinicRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)->put(route('user-profile-information.update'), [
        'first_name' => 'Updated',
        'last_name' => 'Alice',
    ])->assertRedirect();

    // Owner-A's data is updated
    expect($ownerA->fresh()->first_name)->toBe('Updated')
        ->and($ownerA->fresh()->last_name)->toBe('Alice');

    // Owner-B's data is completely untouched
    expect($ownerB->fresh()->first_name)->toBe('Bob')
        ->and($ownerB->fresh()->last_name)->toBe('B')
        ->and($ownerB->fresh()->email)->toBe('ownerb@example.com');
});

it('the profile information endpoint takes no user id parameter — mutation is always scoped to $request->user()', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create(['first_name' => 'Alice']);
    $ownerB = User::factory()->create(['first_name' => 'Bob']);

    settingsIsoClinicRole($ownerA, 'owner', $clinicA->id);
    settingsIsoClinicRole($ownerB, 'owner', $clinicB->id);

    // Submit the profile update as owner-A (no user id in the request body)
    $this->actingAs($ownerA)->put(route('user-profile-information.update'), [
        'first_name' => 'Changed',
        'last_name' => $ownerA->last_name,
    ])->assertRedirect();

    // A's name changed, B's did not
    expect($ownerA->fresh()->first_name)->toBe('Changed')
        ->and($ownerB->fresh()->first_name)->toBe('Bob');
});

// ---------------------------------------------------------------------------
// PUT /user/password — cross-user isolation
// ---------------------------------------------------------------------------

it('password update by owner-A does not change owner-B password hash', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create(['password' => Hash::make('passwordA12345')]);
    $ownerB = User::factory()->create(['password' => Hash::make('passwordB12345')]);

    settingsIsoClinicRole($ownerA, 'owner', $clinicA->id);
    settingsIsoClinicRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)->put(route('user-password.update'), [
        'current_password' => 'passwordA12345',
        'password' => 'newpasswordA12345',
        'password_confirmation' => 'newpasswordA12345',
    ])->assertRedirect();

    // Owner-A now uses their new password
    expect(Hash::check('newpasswordA12345', $ownerA->fresh()->password))->toBeTrue();

    // Owner-B's hash is completely untouched
    expect(Hash::check('passwordB12345', $ownerB->fresh()->password))->toBeTrue();
});

it('the password endpoint takes no user id — mutation is always scoped to $request->user()', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create(['password' => Hash::make('passwordA12345')]);
    $ownerB = User::factory()->create(['password' => Hash::make('passwordB12345')]);

    settingsIsoClinicRole($ownerA, 'owner', $clinicA->id);
    settingsIsoClinicRole($ownerB, 'owner', $clinicB->id);

    // Owner-A changes their own password
    $this->actingAs($ownerA)->put(route('user-password.update'), [
        'current_password' => 'passwordA12345',
        'password' => 'exploited12345',
        'password_confirmation' => 'exploited12345',
    ])->assertRedirect();

    // Owner-B's password is still their original hash — cannot be exploited
    expect(Hash::check('passwordB12345', $ownerB->fresh()->password))->toBeTrue();
});
