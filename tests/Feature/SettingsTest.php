<?php

use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

// ---------------------------------------------------------------------------
// GET /settings — page rendering
// ---------------------------------------------------------------------------

it('renders the settings page for an authenticated user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('settings'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('settings/Index'));
});

it('redirects guests away from the settings page to login', function (): void {
    $this->get(route('settings'))
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// PUT /user/profile-information — happy path
// ---------------------------------------------------------------------------

it('updates profile information for the authenticated user', function (): void {
    $user = User::factory()->create([
        'first_name' => 'Ali',
        'last_name' => 'Yılmaz',
        'email' => 'ali@example.com',
    ]);

    $this->actingAs($user)->put(route('user-profile-information.update'), [
        'first_name' => 'Ayşe',
        'last_name' => 'Kaya',
    ])->assertRedirect();

    expect($user->fresh()->first_name)->toBe('Ayşe')
        ->and($user->fresh()->last_name)->toBe('Kaya')
        ->and($user->fresh()->email)->toBe('ali@example.com');
});

it('flashes a success toast after updating profile information', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('user-profile-information.update'), [
        'first_name' => 'Ayşe',
        'last_name' => 'Kaya',
    ])->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// PUT /user/profile-information — validation matrix
// ---------------------------------------------------------------------------

it('rejects profile update with invalid data and leaves the user unchanged', function (array $overrides, string $field): void {
    $user = User::factory()->create([
        'first_name' => 'Ali',
        'last_name' => 'Yılmaz',
    ]);

    $this->actingAs($user)->put(route('user-profile-information.update'), array_merge([
        'first_name' => 'Ali',
        'last_name' => 'Yılmaz',
    ], $overrides))
        ->assertSessionHasErrorsIn('updateProfileInformation', [$field]);

    expect($user->fresh()->first_name)->toBe('Ali')
        ->and($user->fresh()->last_name)->toBe('Yılmaz');
})->with([
    'blank first_name' => [['first_name' => ''], 'first_name'],
    'oversized first_name' => [['first_name' => str_repeat('a', 101)], 'first_name'],
    'blank last_name' => [['last_name' => ''], 'last_name'],
    'oversized last_name' => [['last_name' => str_repeat('b', 101)], 'last_name'],
]);

it('ignores an email sent in the profile update payload', function (): void {
    $user = User::factory()->create(['email' => 'mine@example.com']);

    $this->actingAs($user)->put(route('user-profile-information.update'), [
        'first_name' => $user->first_name,
        'last_name' => $user->last_name,
        'email' => 'attacker@example.com',
    ])->assertRedirect();

    expect($user->fresh()->email)->toBe('mine@example.com');
});

// ---------------------------------------------------------------------------
// PUT /user/password — happy path
// ---------------------------------------------------------------------------

it('updates the user password when current password is correct', function (): void {
    $user = User::factory()->create(['password' => Hash::make('oldpassword12345')]);

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'oldpassword12345',
        'password' => 'newpassword12345',
        'password_confirmation' => 'newpassword12345',
    ])->assertRedirect();

    expect(Hash::check('newpassword12345', $user->fresh()->password))->toBeTrue();
});

it('flashes a success toast after updating the password', function (): void {
    $user = User::factory()->create(['password' => Hash::make('oldpassword12345')]);

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'oldpassword12345',
        'password' => 'newpassword12345',
        'password_confirmation' => 'newpassword12345',
    ])->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// PUT /user/password — failure cases (errors in bag updatePassword, hash unchanged)
// ---------------------------------------------------------------------------

it('rejects password update with wrong current password and leaves hash unchanged', function (): void {
    $user = User::factory()->create(['password' => Hash::make('correctpassword12345')]);

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'wrongpassword99',
        'password' => 'newpassword12345',
        'password_confirmation' => 'newpassword12345',
    ])->assertSessionHasErrorsIn('updatePassword', ['current_password']);

    expect(Hash::check('correctpassword12345', $user->fresh()->password))->toBeTrue();
});

it('rejects password update when new password is unconfirmed and leaves hash unchanged', function (): void {
    $user = User::factory()->create(['password' => Hash::make('currentpassword12345')]);

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'currentpassword12345',
        'password' => 'newpassword12345',
        'password_confirmation' => 'different12345678',
    ])->assertSessionHasErrorsIn('updatePassword', ['password']);

    expect(Hash::check('currentpassword12345', $user->fresh()->password))->toBeTrue();
});

it('rejects password update when new password is too short and leaves hash unchanged', function (): void {
    $user = User::factory()->create(['password' => Hash::make('currentpassword12345')]);

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'currentpassword12345',
        'password' => 'short',
        'password_confirmation' => 'short',
    ])->assertSessionHasErrorsIn('updatePassword', ['password']);

    expect(Hash::check('currentpassword12345', $user->fresh()->password))->toBeTrue();
});
