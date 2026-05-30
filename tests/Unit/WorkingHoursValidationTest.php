<?php

use App\Models\Clinic;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role to a user (Spatie Teams: clinic_id on assignment).
 */
function whTestRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Submit a PUT /clinic with the given working_hours and return the response.
 * The other fields are always valid so only working_hours can cause a failure.
 *
 * @param  array<string, mixed>  $workingHours
 */
function submitWorkingHours(Clinic $clinic, User $owner, array $workingHours): TestResponse
{
    return test()->actingAs($owner)->put(route('clinic.update'), [
        'name' => 'Test Clinic',
        'slug' => 'test-clinic-'.$clinic->id,
        'description' => null,
        'phone' => null,
        'email' => null,
        'website' => null,
        'country_id' => $clinic->country_id,
        'city_id' => $clinic->city_id,
        'district' => null,
        'address' => null,
        'postal_code' => null,
        'default_slot_duration_minutes' => 30,
        'working_hours' => $workingHours,
    ]);
}

// ---------------------------------------------------------------------------
// Valid working_hours structures (should pass)
// ---------------------------------------------------------------------------

it('accepts a closed day (closed: true, no open/close)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'saturday' => ['closed' => true],
        'sunday' => ['closed' => true],
    ]);

    submitWorkingHours($clinic, $owner, $hours)->assertRedirect();
    expect(session()->get('errors'))->toBeNull();
});

it('accepts an open day without a break (break: null)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '08:00', 'close' => '18:00', 'break' => null],
    ]);

    submitWorkingHours($clinic, $owner, $hours)->assertRedirect();
    expect(session()->get('errors'))->toBeNull();
});

it('accepts an open day with a valid break inside opening hours', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '09:00', 'close' => '18:00', 'break' => ['12:30', '13:30']],
    ]);

    submitWorkingHours($clinic, $owner, $hours)->assertRedirect();
    expect(session()->get('errors'))->toBeNull();
});

// ---------------------------------------------------------------------------
// Invalid working_hours structures (should fail with validation errors)
// ---------------------------------------------------------------------------

it('rejects when close time equals open time', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '09:00', 'close' => '09:00', 'break' => null],
    ]);

    submitWorkingHours($clinic, $owner, $hours)
        ->assertSessionHasErrors('working_hours.monday');
});

it('rejects when close time is before open time', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '17:00', 'close' => '09:00', 'break' => null],
    ]);

    submitWorkingHours($clinic, $owner, $hours)
        ->assertSessionHasErrors('working_hours.monday');
});

it('rejects when break starts before opening time', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '09:00', 'close' => '17:00', 'break' => ['07:00', '08:00']],
    ]);

    submitWorkingHours($clinic, $owner, $hours)
        ->assertSessionHasErrors('working_hours.monday');
});

it('rejects when break ends after closing time', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '09:00', 'close' => '17:00', 'break' => ['16:00', '18:00']],
    ]);

    submitWorkingHours($clinic, $owner, $hours)
        ->assertSessionHasErrors('working_hours.monday');
});

it('rejects when break end is before break start', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '09:00', 'close' => '17:00', 'break' => ['13:00', '12:00']],
    ]);

    submitWorkingHours($clinic, $owner, $hours)
        ->assertSessionHasErrors('working_hours.monday');
});

it('rejects when a required day key is missing (fewer than 7 keys)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $incompleteHours = array_slice(Clinic::defaultWorkingHours(), 0, 6, true); // drop sunday

    submitWorkingHours($clinic, $owner, $incompleteHours)
        ->assertSessionHasErrors('working_hours');
});

it('rejects when a closed day also carries open and close fields', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'sunday' => ['closed' => true, 'open' => '09:00', 'close' => '17:00'],
    ]);

    submitWorkingHours($clinic, $owner, $hours)
        ->assertSessionHasErrors('working_hours.sunday');
});

it('rejects when an open/close day is missing the open field', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    whTestRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['close' => '17:00', 'break' => null], // open missing
    ]);

    submitWorkingHours($clinic, $owner, $hours)
        ->assertSessionHasErrors('working_hours.monday');
});
