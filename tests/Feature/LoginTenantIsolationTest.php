<?php

use App\Models\Clinic;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Assign a clinic-scoped role to a user (Spatie Teams).
 */
function isoClinicRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// Role-scoping (Spatie Teams) isolation
// ---------------------------------------------------------------------------

it("clinic A owner's role is invisible under clinic B's Spatie team", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    isoClinicRole($ownerA, 'owner', $clinicA->id);

    // Assert owns clinic A
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicA->id);
    $ownerA->unsetRelation('roles');
    expect($ownerA->hasRole('owner'))->toBeTrue();

    // Assert does NOT own clinic B
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicB->id);
    $ownerA->unsetRelation('roles');
    expect($ownerA->hasRole('owner'))->toBeFalse();
});

it("clinic B owner's role is invisible under clinic A's Spatie team", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerB = User::factory()->create();
    isoClinicRole($ownerB, 'owner', $clinicB->id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicA->id);
    $ownerB->unsetRelation('roles');
    expect($ownerB->hasRole('owner'))->toBeFalse();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicB->id);
    $ownerB->unsetRelation('roles');
    expect($ownerB->hasRole('owner'))->toBeTrue();
});

// ---------------------------------------------------------------------------
// SetClinicContext middleware isolation — login redirects
// ---------------------------------------------------------------------------

it('resolves clinic A context after owner-A logs in via email', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    isoClinicRole($ownerA, 'owner', $clinicA->id);

    // Log in as owner-A; custom LoginResponse fires and redirects to dashboard
    $this->post(route('login.store'), [
        'email' => $ownerA->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));

    // Follow the redirect — SetClinicContext runs on the next request
    $this->actingAs($ownerA)
        ->get(route('dashboard'))
        ->assertOk();

    // After the request, the singleton holds clinic A's id
    expect(app(ClinicContext::class)->id())->toBe($clinicA->id);
});

it('resolves clinic B context after owner-B logs in, not clinic A', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerB = User::factory()->create();
    isoClinicRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerB)
        ->get(route('dashboard'))
        ->assertOk();

    expect(app(ClinicContext::class)->id())->toBe($clinicB->id);
    expect(app(ClinicContext::class)->id())->not->toBe($clinicA->id);
});

it('owner-A and owner-B each resolve to their own clinic context independently', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    isoClinicRole($ownerA, 'owner', $clinicA->id);
    isoClinicRole($ownerB, 'owner', $clinicB->id);

    // Request as owner-A
    $this->actingAs($ownerA)->get(route('dashboard'));
    expect(app(ClinicContext::class)->id())->toBe($clinicA->id);

    // Reset context between simulated requests
    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Request as owner-B
    $this->actingAs($ownerB)->get(route('dashboard'));
    expect(app(ClinicContext::class)->id())->toBe($clinicB->id);
});

// ---------------------------------------------------------------------------
// OTP path — multi-tenant isolation
// ---------------------------------------------------------------------------

it('resolves clinic A context after owner-A authenticates via OTP', function (): void {
    $clinicA = Clinic::factory()->create();

    $ownerA = User::factory()->create([
        'phone' => '+905321112233',
        'phone_verified_at' => now(),
    ]);
    isoClinicRole($ownerA, 'owner', $clinicA->id);

    $code = '654321';
    Cache::put('auth:otp:+905321112233', Hash::make($code), 300);
    Cache::put('auth:otp:attempts:+905321112233', 0, 300);

    $this->post(route('login.otp.verify'), [
        'phone' => '0532 111 22 33',
        'code' => $code,
    ])->assertRedirect(route('dashboard'));

    // Confirm the authenticated user is owner-A
    $this->assertAuthenticatedAs($ownerA);

    // Follow-up request binds clinic A to the context
    app(ClinicContext::class)->forget();
    $this->actingAs($ownerA)->get(route('dashboard'));
    expect(app(ClinicContext::class)->id())->toBe($clinicA->id);
});

it('global admin has no clinic context after login', function (): void {
    $superadmin = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $superadmin->assignRole('superadmin');

    $this->actingAs($superadmin)->get(route('admin'));

    // Global admin has no clinic-scoped role → ClinicContext stays null
    expect(app(ClinicContext::class)->id())->toBeNull();
});
