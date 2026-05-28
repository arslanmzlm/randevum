<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

// ---------------------------------------------------------------------------
// Helpers (global functions — unique names to avoid collisions with other tests)
// ---------------------------------------------------------------------------

/**
 * Assign a clinic-scoped role to a user (Spatie Teams: clinic_id on assignment).
 */
function loginTestClinicRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Assign a global (clinic_id = null) role to a user.
 */
function loginTestGlobalRole(User $user, string $role): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->assignRole($role);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// Login page
// ---------------------------------------------------------------------------

it('renders the login page with expected Inertia props', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Login')
            ->has('canResetPassword')
            ->has('canLoginWithOtp')
            ->has('status')
            ->where('canResetPassword', true)
            ->where('canLoginWithOtp', true)
        );
});

it('redirects authenticated users away from the login page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('login'))
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// Email + password login — success + role redirect matrix
// ---------------------------------------------------------------------------

it('authenticates with valid email and password', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect();

    $this->assertAuthenticated();
});

it('redirects a clinic owner to the dashboard after login', function (): void {
    $clinic = Clinic::factory()->create();
    $user = User::factory()->create();
    loginTestClinicRole($user, 'owner', $clinic->id);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

it('redirects a clinic doctor to the dashboard after login', function (): void {
    $clinic = Clinic::factory()->create();
    $user = User::factory()->create();
    loginTestClinicRole($user, 'doctor', $clinic->id);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

it('redirects a clinic manager to the dashboard after login', function (): void {
    $clinic = Clinic::factory()->create();
    $user = User::factory()->create();
    loginTestClinicRole($user, 'manager', $clinic->id);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

it('redirects a superadmin to the admin route after login', function (): void {
    $user = User::factory()->create();
    loginTestGlobalRole($user, 'superadmin');

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('admin'));
});

it('redirects an admin to the admin route after login', function (): void {
    $user = User::factory()->create();
    loginTestGlobalRole($user, 'admin');

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('admin'));
});

it('redirects a moderator to the admin route after login', function (): void {
    $user = User::factory()->create();
    loginTestGlobalRole($user, 'moderator');

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('admin'));
});

it('falls back to dashboard for a user with no role', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('dashboard'));
});

// ---------------------------------------------------------------------------
// Email + password login — failure
// ---------------------------------------------------------------------------

it('rejects an incorrect password and keeps the user as a guest', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ]);

    $this->assertGuest();
});

it('returns a validation error on the email field for bad credentials', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'wrong-password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('rejects a login attempt for a non-existent email', function (): void {
    $this->post(route('login.store'), [
        'email' => 'nobody@example.com',
        'password' => 'password',
    ])->assertSessionHasErrors('email');

    $this->assertGuest();
});

// ---------------------------------------------------------------------------
// last_login_at stamping — email path
// ---------------------------------------------------------------------------

it('stamps last_login_at after a successful email login', function (): void {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

it('does not stamp last_login_at after a failed login attempt', function (): void {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'bad-password',
    ]);

    expect($user->fresh()->last_login_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// Logout
// ---------------------------------------------------------------------------

it('logs the user out and redirects to the login page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('logout'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
});

// ---------------------------------------------------------------------------
// Protected routes require authentication
// ---------------------------------------------------------------------------

it('redirects guests away from the dashboard', function (): void {
    $this->get(route('dashboard'))->assertRedirect(route('login'));
});

it('redirects guests away from the admin route', function (): void {
    $this->get(route('admin'))->assertRedirect(route('login'));
});

it('allows authenticated users to access the dashboard page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('dashboard'))->assertOk();
});
