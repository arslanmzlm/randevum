<?php

use App\Models\Clinic;
use App\Models\Country;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use Database\Seeders\CountrySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, CountrySeeder::class, VerticalSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->vertical = Vertical::where('is_active', true)->first();
});

/**
 * Base valid registration payload for RegisterTest.
 */
function registerTestValidPayload(int $verticalId): array
{
    return [
        'first_name' => 'Ali',
        'last_name' => 'Yılmaz',
        'email' => 'ali@example.com',
        'password' => 'password12345',
        'password_confirmation' => 'password12345',
        'vertical_id' => $verticalId,
        'clinic_name' => 'Test Klinik',
        'terms' => true,
    ];
}

// ---------------------------------------------------------------------------
// GET /register — page rendering
// ---------------------------------------------------------------------------

it('renders the register page with expected Inertia component', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->has('verticals')
            ->has('status')
        );
});

it('register page includes the active vertical in the verticals prop', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('auth/Register')
            ->has('verticals', 1)
            ->where('verticals.0.id', $this->vertical->id)
            ->where('verticals.0.slug', 'podiatry')
            ->has('verticals.0.name')
        );
});

it('register page status prop is null when no flash', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('status', null)
        );
});

it('redirects authenticated users away from the register page', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('register'))
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// POST /register — happy path
// ---------------------------------------------------------------------------

it('creates exactly one tenant, one clinic, and one user on successful registration', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    expect(Tenant::count())->toBe(1)
        ->and(Clinic::count())->toBe(1)
        ->and(User::count())->toBe(1);
});

it('creates the clinic with the correct name and vertical', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $clinic = Clinic::first();

    expect($clinic->name)->toBe('Test Klinik')
        ->and($clinic->vertical_id)->toBe($this->vertical->id);
});

it('creates the clinic with a non-null unique slug', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $clinic = Clinic::first();

    expect($clinic->slug)->not->toBeNull()
        ->and($clinic->slug)->not->toBeEmpty();
});

it('creates the clinic linked to the TR country', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $countryId = Country::where('code', 'TR')->value('id');
    $clinic = Clinic::first();

    expect($clinic->country_id)->toBe($countryId);
});

it('creates the clinic with the default working hours', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $clinic = Clinic::first();

    expect($clinic->working_hours)->toBe(Clinic::defaultWorkingHours());
});

it('creates the clinic with onboarded_at set', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $clinic = Clinic::first();

    expect($clinic->onboarded_at)->not->toBeNull();
});

it('creates the clinic with phone, address, and city_id left null (thin onboarding)', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $clinic = Clinic::first();

    expect($clinic->phone)->toBeNull()
        ->and($clinic->address)->toBeNull()
        ->and($clinic->city_id)->toBeNull();
});

it('authenticates the user after successful registration', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $this->assertAuthenticated();
});

it('redirects to the dashboard after successful registration', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id))
        ->assertRedirect(route('dashboard'));
});

it('stamps last_login_at after successful registration', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $user = User::first();

    expect($user->last_login_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Owner role scoping (Spatie Teams)
// ---------------------------------------------------------------------------

it('assigns the owner role scoped to the newly created clinic', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $user = User::first();
    $clinic = Clinic::first();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    $user->unsetRelation('roles');

    expect($user->hasRole('owner'))->toBeTrue();
});

it('owner role is not visible under the null team context', function (): void {
    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $user = User::first();

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');

    expect($user->hasRole('owner'))->toBeFalse();
});

it('owner role is not visible under a different clinic team context', function (): void {
    $otherClinic = Clinic::factory()->create();

    $this->post(route('register.store'), registerTestValidPayload($this->vertical->id));

    $user = User::where('email', 'ali@example.com')->first();

    app(PermissionRegistrar::class)->setPermissionsTeamId($otherClinic->id);
    $user->unsetRelation('roles');

    expect($user->hasRole('owner'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Slug uniqueness
// ---------------------------------------------------------------------------

it('generates distinct slugs for two registrations with the same clinic name', function (): void {
    $payload = registerTestValidPayload($this->vertical->id);
    $this->post(route('register.store'), $payload);

    // Log out so the guest middleware permits a second registration
    auth()->logout();

    $payload['email'] = 'second@example.com';
    $this->post(route('register.store'), $payload);

    $slugs = Clinic::pluck('slug');

    expect($slugs)->toHaveCount(2)
        ->and($slugs[0])->not->toBe($slugs[1]);
});

// ---------------------------------------------------------------------------
// Validation matrix — each invalid payload rolls back: 0 tenants / clinics / users
// ---------------------------------------------------------------------------

it('rejects registration with invalid payload and creates nothing', function (array $overrides, string $errorKey): void {
    $payload = array_merge(registerTestValidPayload($this->vertical->id), $overrides);

    $this->post(route('register.store'), $payload)
        ->assertSessionHasErrors($errorKey);

    expect(Tenant::count())->toBe(0)
        ->and(Clinic::count())->toBe(0)
        ->and(User::count())->toBe(0);
})->with([
    'blank first_name' => [['first_name' => ''], 'first_name'],
    'oversized first_name' => [['first_name' => str_repeat('a', 101)], 'first_name'],
    'blank last_name' => [['last_name' => ''], 'last_name'],
    'oversized last_name' => [['last_name' => str_repeat('b', 101)], 'last_name'],
    'invalid email format' => [['email' => 'not-an-email'], 'email'],
    'unconfirmed password' => [['password_confirmation' => 'different12345'], 'password'],
    'too short password' => [['password' => 'short', 'password_confirmation' => 'short'], 'password'],
    'missing vertical_id' => [['vertical_id' => ''], 'vertical_id'],
    'nonexistent vertical_id' => [['vertical_id' => 99999], 'vertical_id'],
    'blank clinic_name' => [['clinic_name' => ''], 'clinic_name'],
    'oversized clinic_name' => [['clinic_name' => str_repeat('c', 256)], 'clinic_name'],
    'unaccepted terms' => [['terms' => false], 'terms'],
]);

it('rejects a duplicate email and creates nothing new', function (): void {
    User::factory()->create(['email' => 'existing@example.com']);

    $payload = registerTestValidPayload($this->vertical->id);
    $payload['email'] = 'existing@example.com';

    $this->post(route('register.store'), $payload)
        ->assertSessionHasErrors('email');

    // Pre-existing user remains; registration created nothing
    expect(Tenant::count())->toBe(0)
        ->and(Clinic::count())->toBe(0)
        ->and(User::count())->toBe(1);
});

it('rejects an inactive vertical_id and creates nothing', function (): void {
    $inactiveVertical = Vertical::factory()->create(['is_active' => false]);

    $payload = registerTestValidPayload($this->vertical->id);
    $payload['vertical_id'] = $inactiveVertical->id;

    $this->post(route('register.store'), $payload)
        ->assertSessionHasErrors('vertical_id');

    expect(Tenant::count())->toBe(0)
        ->and(Clinic::count())->toBe(0)
        ->and(User::count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Rate limiting — POST /register is throttled (5/min per IP)
// ---------------------------------------------------------------------------

it('throttles the registration endpoint after 5 attempts per minute', function (): void {
    // Invalid (empty) payloads fail validation but still pass through the
    // throttle middleware, so each counts against the limit and none create data.
    for ($i = 0; $i < 5; $i++) {
        $this->post(route('register.store'), [])->assertStatus(302);
    }

    $this->post(route('register.store'), [])->assertStatus(429);
});
