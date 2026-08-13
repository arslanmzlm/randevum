<?php

use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\ScheduleException;
use App\Models\User;
use App\Support\ClinicContext;
use Carbon\Carbon;
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
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function seTestRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a valid POST /schedule-exceptions payload.
 *
 * @return array<string, mixed>
 */
function seStorePayload(array $overrides = []): array
{
    return array_merge([
        'scope' => 'doctor',
        'doctor_id' => null,
        'is_all_day' => false,
        'starts_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
        'ends_at' => now()->addDays(5)->addHours(4)->format('Y-m-d H:i:s'),
        'reason' => 'İzin',
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /schedule-exceptions — viewAny gate
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /schedule-exceptions', function (): void {
    $this->get(route('schedule-exceptions.index'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /schedule-exceptions and the Index component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('availability/Index')
            ->has('exceptions')
            ->has('doctors')
            ->has('auth.permissions')
            ->has('ownDoctorId')
            ->has('timezone')
        );
});

it('index props include timezone from the active clinic', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index'))
        ->assertInertia(fn ($page) => $page->where('timezone', 'Europe/Istanbul'));
});

it('canManage is true for owner', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('scheduleExceptions.manage')));
});

it('canManage is true for manager', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    seTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('schedule-exceptions.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('scheduleExceptions.manage')));
});

it('canManage is true for receptionist', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    seTestRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('schedule-exceptions.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('scheduleExceptions.manage')));
});

it('canManage is false for doctor role', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    seTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('schedule-exceptions.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('scheduleExceptions.manage')));
});

it('canManage is false for assistant role', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    seTestRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('schedule-exceptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('scheduleExceptions.manage')));
});

it('ownDoctorId is set to doctors.id when the user has a profile', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    seTestRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->get(route('schedule-exceptions.index'))
        ->assertInertia(fn ($page) => $page->where('ownDoctorId', $doctor->id));
});

it('ownDoctorId is null when the user has no doctors profile', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index'))
        ->assertInertia(fn ($page) => $page->where('ownDoctorId', null));
});

it('index lists only upcoming exceptions (ends_at >= now)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);

    ScheduleException::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('exceptions', 1)
            ->where('showPast', false)
            ->where('hasPast', true));
});

it('index includes past exceptions when show_past is set', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDays(2),
    ]);

    ScheduleException::factory()->past()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index', ['show_past' => 1]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('exceptions', 2)
            ->where('showPast', true)
            ->where('hasPast', false));
});

it('index doctors prop only contains active doctors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    Doctor::factory()->create(['clinic_id' => $clinic->id, 'is_active' => true]);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'is_active' => false]);

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index'))
        ->assertInertia(fn ($page) => $page->has('doctors', 1));
});

// ---------------------------------------------------------------------------
// POST /schedule-exceptions — authorization
// ---------------------------------------------------------------------------

it('owner can create a single-doctor exception', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctor->id]))
        ->assertRedirect(route('schedule-exceptions.index'));

    expect(ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('doctor_id', $doctor->id)
        ->exists()
    )->toBeTrue();
});

it('manager can create a single-doctor exception', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    seTestRole($manager, 'manager', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($manager)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctor->id]))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->exists()
    )->toBeTrue();
});

it('receptionist can create an exception for another doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    seTestRole($receptionist, 'receptionist', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($receptionist)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctor->id]))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->exists()
    )->toBeTrue();
});

it('a doctor can create an exception for their own profile', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    seTestRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctor->id]))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->exists()
    )->toBeTrue();
});

it('a doctor gets 403 creating an exception for another doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUserA = User::factory()->create();
    $doctorUserB = User::factory()->create();
    seTestRole($doctorUserA, 'doctor', $clinic->id);
    seTestRole($doctorUserB, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($doctorUserA)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctorB->id]))
        ->assertForbidden();

    expect(ScheduleException::withoutGlobalScopes()
        ->where('doctor_id', $doctorB->id)
        ->exists()
    )->toBeFalse();
});

it('a doctor gets 403 creating a clinic-wide exception (scope=clinic)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    seTestRole($doctorUser, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'scope' => 'clinic',
            'doctor_id' => null,
        ]))
        ->assertForbidden();
});

it('an assistant gets 403 when creating an exception (no manage permission, no own profile)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    seTestRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($assistant)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctor->id]))
        ->assertForbidden();
});

it('successful store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctor->id]))
        ->assertSessionHas('toasts');
});

it('created exception has the correct doctor_id, clinic_id, and created_by', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctor->id]))
        ->assertRedirect();

    $exception = ScheduleException::withoutGlobalScopes()
        ->where('doctor_id', $doctor->id)
        ->first();

    expect($exception)->not->toBeNull()
        ->and($exception->clinic_id)->toBe($clinic->id)
        ->and($exception->doctor_id)->toBe($doctor->id)
        ->and($exception->created_by)->toBe($owner->id);
});

// ---------------------------------------------------------------------------
// POST /schedule-exceptions — clinic-wide scope
// ---------------------------------------------------------------------------

it('clinic-wide scope creates one row per active doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    Doctor::factory()->create(['clinic_id' => $clinic->id, 'is_active' => true]);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'is_active' => true]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'scope' => 'clinic',
            'doctor_id' => null,
        ]))
        ->assertRedirect();

    $count = ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->count();

    expect($count)->toBe(2);
});

it('clinic-wide scope skips inactive doctors', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $activeUser = User::factory()->create();
    $inactiveUser = User::factory()->create();
    $activeDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $activeUser->id, 'is_active' => true]);
    $inactiveDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $inactiveUser->id, 'is_active' => false]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'scope' => 'clinic',
            'doctor_id' => null,
        ]))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('doctor_id', $activeDoctor->id)
        ->exists()
    )->toBeTrue();

    expect(ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('doctor_id', $inactiveDoctor->id)
        ->exists()
    )->toBeFalse();
});

it('clinic-wide store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'is_active' => true]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'scope' => 'clinic',
            'doctor_id' => null,
        ]))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// POST /schedule-exceptions — validation
// ---------------------------------------------------------------------------

it('store rejects ends_at before starts_at', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'doctor_id' => $doctor->id,
            'starts_at' => now()->addDays(5)->format('Y-m-d H:i:s'),
            'ends_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
        ]))
        ->assertSessionHasErrors('ends_at');
});

it('store requires starts_at', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'doctor_id' => $doctor->id,
            'starts_at' => null,
        ]))
        ->assertSessionHasErrors('starts_at');
});

it('store requires ends_at', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'doctor_id' => $doctor->id,
            'ends_at' => null,
        ]))
        ->assertSessionHasErrors('ends_at');
});

it('store rejects an invalid scope value', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload(['scope' => 'invalid']))
        ->assertSessionHasErrors('scope');
});

it('store requires scope', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $payload = seStorePayload();
    unset($payload['scope']);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), $payload)
        ->assertSessionHasErrors('scope');
});

it('store requires doctor_id when scope=doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'scope' => 'doctor',
            'doctor_id' => null,
        ]))
        ->assertSessionHasErrors('doctor_id');
});

it('store rejects a reason longer than 255 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'doctor_id' => $doctor->id,
            'reason' => str_repeat('a', 256),
        ]))
        ->assertSessionHasErrors('reason');
});

// ---------------------------------------------------------------------------
// DELETE /schedule-exceptions/{scheduleException} — delete
// ---------------------------------------------------------------------------

it('owner can soft-delete a schedule exception', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $exception = ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('schedule-exceptions.destroy', $exception))
        ->assertRedirect(route('schedule-exceptions.index'));

    expect(ScheduleException::withoutGlobalScopes()->find($exception->id)->deleted_at)
        ->not->toBeNull();
});

it('manager can soft-delete any exception', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    seTestRole($manager, 'manager', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $exception = ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($manager)
        ->delete(route('schedule-exceptions.destroy', $exception))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()->find($exception->id)->deleted_at)
        ->not->toBeNull();
});

it('receptionist can soft-delete an exception', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    seTestRole($receptionist, 'receptionist', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $exception = ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($receptionist)
        ->delete(route('schedule-exceptions.destroy', $exception))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()->find($exception->id)->deleted_at)
        ->not->toBeNull();
});

it('a doctor can delete their own exception', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    seTestRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $exception = ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($doctorUser)
        ->delete(route('schedule-exceptions.destroy', $exception))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()->find($exception->id)->deleted_at)
        ->not->toBeNull();
});

it('a doctor gets 403 deleting another doctor\'s exception', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUserA = User::factory()->create();
    $doctorUserB = User::factory()->create();
    seTestRole($doctorUserA, 'doctor', $clinic->id);
    seTestRole($doctorUserB, 'doctor', $clinic->id);
    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserA->id]);
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $exception = ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctorB->id,
    ]);

    $this->actingAs($doctorUserA)
        ->delete(route('schedule-exceptions.destroy', $exception))
        ->assertForbidden();

    expect(ScheduleException::withoutGlobalScopes()->find($exception->id)->deleted_at)
        ->toBeNull();
});

it('successful delete flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $exception = ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($owner)
        ->delete(route('schedule-exceptions.destroy', $exception))
        ->assertSessionHas('toasts');
});

it('assistant gets 403 deleting an exception', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    seTestRole($assistant, 'assistant', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $exception = ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
    ]);

    $this->actingAs($assistant)
        ->delete(route('schedule-exceptions.destroy', $exception))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// All-day normalization
// ---------------------------------------------------------------------------

it('all-day exception spans full local day in clinic timezone', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('schedule-exceptions.store'), [
            'scope' => 'doctor',
            'doctor_id' => $doctor->id,
            'is_all_day' => true,
            'starts_at' => '2026-07-15',
            'ends_at' => '2026-07-15',
            'reason' => null,
        ])
        ->assertRedirect();

    $exception = ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('doctor_id', $doctor->id)
        ->first();

    expect($exception)->not->toBeNull();

    // Convert UTC stored times back to clinic timezone to verify day boundaries.
    $startsLocal = Carbon::parse($exception->starts_at)->setTimezone('Europe/Istanbul');
    $endsLocal = Carbon::parse($exception->ends_at)->setTimezone('Europe/Istanbul');

    expect($startsLocal->hour)->toBe(0)
        ->and($startsLocal->minute)->toBe(0)
        ->and($startsLocal->second)->toBe(0)
        ->and($endsLocal->hour)->toBe(23)
        ->and($endsLocal->minute)->toBe(59)
        ->and($endsLocal->second)->toBe(59);
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it("clinic A's index never exposes clinic B's exceptions", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    seTestRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);
    ScheduleException::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
    ]);

    $this->actingAs($ownerA)
        ->get(route('schedule-exceptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('exceptions', 0));
});

it('clinic A owner gets 404 on DELETE for a clinic B exception', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    seTestRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $exceptionB = ScheduleException::factory()->create([
        'clinic_id' => $clinicB->id,
        'doctor_id' => $doctorB->id,
    ]);

    $this->actingAs($ownerA)
        ->delete(route('schedule-exceptions.destroy', $exceptionB))
        ->assertNotFound();

    expect(ScheduleException::withoutGlobalScopes()->find($exceptionB->id)->deleted_at)
        ->toBeNull();
});

it('store rejects a doctor_id that belongs to a different clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    seTestRole($ownerA, 'owner', $clinicA->id);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($ownerA)
        ->post(route('schedule-exceptions.store'), seStorePayload(['doctor_id' => $doctorB->id]))
        ->assertSessionHasErrors('doctor_id');

    expect(ScheduleException::withoutGlobalScopes()
        ->where('doctor_id', $doctorB->id)
        ->exists()
    )->toBeFalse();
});

it('clinic-wide store only creates rows for clinic A doctors, not clinic B doctors', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    seTestRole($ownerA, 'owner', $clinicA->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id, 'is_active' => true]);

    $doctorUserB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $doctorUserB->id, 'is_active' => true]);

    $this->actingAs($ownerA)
        ->post(route('schedule-exceptions.store'), seStorePayload([
            'scope' => 'clinic',
            'doctor_id' => null,
        ]))
        ->assertRedirect();

    expect(ScheduleException::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('doctor_id', $doctorA->id)
        ->exists()
    )->toBeTrue();

    expect(ScheduleException::withoutGlobalScopes()
        ->where('doctor_id', $doctorB->id)
        ->exists()
    )->toBeFalse();
});

it("clinic A's exceptions are not visible to clinic B's owner", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    seTestRole($ownerA, 'owner', $clinicA->id);
    seTestRole($ownerB, 'owner', $clinicB->id);

    $doctorUserA = User::factory()->create();
    $doctorA = Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $doctorUserA->id]);
    ScheduleException::factory()->create(['clinic_id' => $clinicA->id, 'doctor_id' => $doctorA->id]);

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($ownerB)
        ->get(route('schedule-exceptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('exceptions', 0));
});

it('the leave list flags doctor_is_deleted for a soft-deleted doctor and keeps the name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    seTestRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    ScheduleException::factory()->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'starts_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3)->addHours(2),
    ]);

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('exceptions', 1)
            ->where('exceptions.0.doctor_is_deleted', false)
        );

    $name = $doctor->display_name;
    $doctor->delete();

    $this->actingAs($owner)
        ->get(route('schedule-exceptions.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('exceptions', 1)
            ->where('exceptions.0.doctor_name', $name)
            ->where('exceptions.0.doctor_is_deleted', true)
        );
});
