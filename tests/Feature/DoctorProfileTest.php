<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
function drTestRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a valid PUT /doctors/{doctor} payload.
 *
 * @return array<string, mixed>
 */
function drUpdatePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Test',
        'last_name' => 'Doctor',
        'title' => 'Dr.',
        'specialization' => 'Podoloji',
        'bio' => 'Some bio.',
        'license_number' => 'TR-12345',
        'certificate' => 'Certificate A, Certificate B',
        'is_active' => true,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /doctors — access control + rendering
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /doctors', function (): void {
    $this->get(route('doctors.index'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /doctors and the index component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('doctors/Index')
            ->has('doctors')
            ->has('auth.permissions')
            ->has('hasOwnProfile')
        );
});

it('index canManage is true for owner and false for doctor role', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('doctors.update')));

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($doctorUser)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('doctors.update')));
});

it('index lists only the active clinic\'s doctors', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinicA->id);

    $userA1 = User::factory()->create(['first_name' => 'Doctor', 'last_name' => 'Alpha']);
    $userB1 = User::factory()->create(['first_name' => 'Doctor', 'last_name' => 'Beta']);

    Doctor::factory()->create(['clinic_id' => $clinicA->id, 'user_id' => $userA1->id]);
    Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $userB1->id]);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('doctors', 1)
            ->where('doctors.0.name', 'Doctor Alpha')
        );
});

it('index hasOwnProfile is true when the owner already has a doctors row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page->where('hasOwnProfile', true));
});

it('index hasOwnProfile is false when the owner has no doctors row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page->where('hasOwnProfile', false));
});

// ---------------------------------------------------------------------------
// display_name computation
// ---------------------------------------------------------------------------

it('display_name prefixes the title when title is set', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create(['first_name' => 'Ayse', 'last_name' => 'Kaya']);
    drTestRole($owner, 'owner', $clinic->id);

    Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $owner->id,
        'title' => 'Dr.',
    ]);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page
            ->where('doctors.0.display_name', 'Dr. Ayse Kaya')
        );
});

it('display_name equals the plain name when title is null', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create(['first_name' => 'Mehmet', 'last_name' => 'Demir']);
    drTestRole($owner, 'owner', $clinic->id);

    Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $owner->id,
        'title' => null,
    ]);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page
            ->where('doctors.0.display_name', 'Mehmet Demir')
        );
});

// ---------------------------------------------------------------------------
// POST /doctors/self — owner self-create
// ---------------------------------------------------------------------------

it('owner can self-create a doctors row via POST /doctors/self', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    expect(Doctor::withoutGlobalScopes()->where('user_id', $owner->id)->exists())->toBeFalse();

    $this->actingAs($owner)
        ->post(route('doctors.storeOwn'))
        ->assertRedirect();

    $doctor = Doctor::withoutGlobalScopes()->where('user_id', $owner->id)->first();

    expect($doctor)->not->toBeNull()
        ->and($doctor->user_id)->toBe($owner->id)
        ->and($doctor->clinic_id)->toBe($clinic->id);
});

it('self-create flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('doctors.storeOwn'))
        ->assertSessionHas('toasts');
});

it('a second self-create is rejected when user already has a profile', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('doctors.storeOwn'))
        ->assertSessionHasErrors();

    expect(Doctor::withoutGlobalScopes()->where('user_id', $owner->id)->count())->toBe(1);
});

it('a non-owner user gets 403 on POST /doctors/self', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('doctors.storeOwn'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// GET /doctors/{doctor}/edit — access control + rendering
// ---------------------------------------------------------------------------

it('owner can access the edit page for any clinic doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->get(route('doctors.edit', $doctor))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('doctors/Edit')
            ->where('doctor.id', $doctor->id)
            ->has('auth.permissions')
            ->has('canEditSelf')
        );
});

it('a doctor can access the edit page for their own profile', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->get(route('doctors.edit', $doctor))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('doctors/Edit'));
});

it('a doctor gets 403 editing another doctor\'s profile', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUserA = User::factory()->create();
    $doctorUserB = User::factory()->create();
    drTestRole($doctorUserA, 'doctor', $clinic->id);
    drTestRole($doctorUserB, 'doctor', $clinic->id);

    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($doctorUserA)
        ->get(route('doctors.edit', $doctorB))
        ->assertForbidden();
});

it('edit props include display_name, title, certificate', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create(['first_name' => 'Ali', 'last_name' => 'Yilmaz']);
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $owner->id,
        'title' => 'Prof. Dr.',
        'certificate' => 'Board Certified',
    ]);

    $this->actingAs($owner)
        ->get(route('doctors.edit', $doctor))
        ->assertInertia(fn ($page) => $page
            ->where('doctor.display_name', 'Prof. Dr. Ali Yilmaz')
            ->where('doctor.title', 'Prof. Dr.')
            ->where('doctor.certificate', 'Board Certified')
        );
});

it('edit canEditSelf is true when the acting user owns the profile', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('doctors.edit', $doctor))
        ->assertInertia(fn ($page) => $page->where('canEditSelf', true));
});

it('edit canEditSelf is false when the acting user does not own the profile', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $otherUser->id]);

    $this->actingAs($owner)
        ->get(route('doctors.edit', $doctor))
        ->assertInertia(fn ($page) => $page->where('canEditSelf', false));
});

// ---------------------------------------------------------------------------
// PUT /doctors/{doctor} — update
// ---------------------------------------------------------------------------

it('owner can update any doctor profile including is_active', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
        'is_active' => true,
        'title' => null,
    ]);

    $this->actingAs($owner)
        ->put(route('doctors.update', $doctor), drUpdatePayload([
            'title' => 'Doc. Dr.',
            'is_active' => false,
        ]))
        ->assertRedirect();

    $fresh = $doctor->fresh();
    expect($fresh->title)->toBe('Doc. Dr.')
        ->and($fresh->is_active)->toBeFalse();
});

it('title and certificate round-trip through an update', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $owner->id,
        'title' => null,
        'certificate' => null,
    ]);

    $this->actingAs($owner)
        ->put(route('doctors.update', $doctor), drUpdatePayload([
            'title' => 'Op. Dr.',
            'certificate' => 'APMA Member, EMED Certified',
        ]))
        ->assertRedirect();

    $fresh = $doctor->fresh();
    expect($fresh->title)->toBe('Op. Dr.')
        ->and($fresh->certificate)->toBe('APMA Member, EMED Certified');
});

it('a doctor updating own profile cannot flip is_active — service strips it', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
        'is_active' => true,
    ]);

    $this->actingAs($doctorUser)
        ->put(route('doctors.update', $doctor), drUpdatePayload(['is_active' => false]))
        ->assertRedirect();

    // is_active must remain true — service strips it for non-owners
    expect($doctor->fresh()->is_active)->toBeTrue();
});

it('successful PUT /doctors/{doctor} flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->put(route('doctors.update', $doctor), drUpdatePayload())
        ->assertSessionHas('toasts');
});

it('a doctor gets 403 updating another doctor profile via PUT', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUserA = User::factory()->create();
    $doctorUserB = User::factory()->create();
    drTestRole($doctorUserA, 'doctor', $clinic->id);
    drTestRole($doctorUserB, 'doctor', $clinic->id);

    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($doctorUserA)
        ->put(route('doctors.update', $doctorB), drUpdatePayload())
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// GET /doctors/me — mine route
// ---------------------------------------------------------------------------

it('doctors.mine renders the own profile edit for a user who has a doctors row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('doctors.mine'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('doctors/Edit')
            ->where('doctor.id', $doctor->id)
        );
});

it('doctors.mine redirects to index with a toast for a user with no doctors row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('doctors.mine'))
        ->assertRedirect(route('doctors.index'))
        ->assertSessionHas('toasts');
});

it('doctors.mine also works for a doctor-role user who has a profile', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($doctorUser)
        ->get(route('doctors.mine'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('doctors/Edit')
            ->where('doctor.id', $doctor->id)
        );
});

// ---------------------------------------------------------------------------
// Avatar upload / remove
// ---------------------------------------------------------------------------

it('owner can upload an avatar for a doctor', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->post(route('doctors.avatar.update', $doctor), [
            'image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ])
        ->assertRedirect();

    expect($doctor->fresh()->getMedia('avatar'))->toHaveCount(1);
});

it('avatar upload flashes a success toast', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('doctors.avatar.update', $doctor), [
            'image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ])
        ->assertSessionHas('toasts');
});

it('rejects an avatar image smaller than 256x256', function (): void {
    Storage::fake(config('media-library.disk_name'));

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->post(route('doctors.avatar.update', $doctor), [
            'image' => UploadedFile::fake()->image('tiny.jpg', 100, 100),
        ])
        ->assertSessionHasErrors('image');

    expect($doctor->fresh()->getMedia('avatar'))->toHaveCount(0);
});

it('owner can remove a doctor avatar and the collection becomes empty', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $doctor->addMedia(UploadedFile::fake()->image('avatar.jpg', 300, 300))
        ->toMediaCollection('avatar');

    expect($doctor->getMedia('avatar'))->toHaveCount(1);

    $this->actingAs($owner)
        ->delete(route('doctors.avatar.remove', $doctor))
        ->assertRedirect();

    expect($doctor->fresh()->getMedia('avatar'))->toHaveCount(0);
});

it('avatar remove flashes a success toast', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->delete(route('doctors.avatar.remove', $doctor))
        ->assertSessionHas('toasts');
});

it('a non-owner / non-self user gets 403 on avatar upload', function (): void {
    Storage::fake(config('media-library.disk_name'));

    $clinic = Clinic::factory()->create();
    $doctorUserA = User::factory()->create();
    $doctorUserB = User::factory()->create();
    drTestRole($doctorUserA, 'doctor', $clinic->id);
    drTestRole($doctorUserB, 'doctor', $clinic->id);

    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($doctorUserA)
        ->post(route('doctors.avatar.update', $doctorB), [
            'image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// canCreateOwn prop — index page
// ---------------------------------------------------------------------------

it('index canCreateOwn is true for owner without a doctors row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page->where('canCreateOwn', true));
});

it('index canCreateOwn is false for owner who already has a doctors row', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page->where('canCreateOwn', false));
});

it('index canCreateOwn is false for manager role', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    drTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('doctors.index'))
        ->assertInertia(fn ($page) => $page->where('canCreateOwn', false));
});

// ---------------------------------------------------------------------------
// GET /doctors/create — access + rendering
// ---------------------------------------------------------------------------

it('owner can access the create page and the Create component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('doctors.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('doctors/Create'));
});

it('doctor role gets 403 on GET /doctors/create', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('doctors.create'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// POST /doctors — add new doctor (owner/manager)
// ---------------------------------------------------------------------------

/**
 * @return array<string, mixed>
 */
function drStorePayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Yeni',
        'last_name' => 'Doktor',
        'email' => 'yeni.doktor@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'title' => 'Dr.',
        'specialization' => 'Podoloji',
        'bio' => null,
        'license_number' => null,
        'certificate' => null,
        'is_active' => true,
    ], $overrides);
}

it('owner can add a new doctor and is redirected to the edit page', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $response = $this->actingAs($owner)
        ->post(route('doctors.store'), drStorePayload());

    $newDoctor = Doctor::withoutGlobalScopes()
        ->whereHas('user', fn ($q) => $q->where('email', 'yeni.doktor@example.com'))
        ->first();

    $response->assertRedirect(route('doctors.edit', $newDoctor));
});

it('POST /doctors creates a user, assigns the clinic-scoped doctor role, and creates a doctor profile', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('doctors.store'), drStorePayload(['email' => 'new.doc@test.com']))
        ->assertRedirect();

    $newUser = User::where('email', 'new.doc@test.com')->first();
    expect($newUser)->not->toBeNull();

    $doctor = Doctor::withoutGlobalScopes()->where('user_id', $newUser->id)->first();
    expect($doctor)->not->toBeNull()
        ->and($doctor->clinic_id)->toBe($clinic->id)
        ->and($doctor->title)->toBe('Dr.');

    // Role must be scoped to the active clinic
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    expect($newUser->fresh()->hasRole('doctor'))->toBeTrue();
});

it('POST /doctors rejects a duplicate email with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $existing = User::factory()->create(['email' => 'taken@example.com']);
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('doctors.store'), drStorePayload(['email' => 'taken@example.com']))
        ->assertSessionHasErrors('email');

    // No new doctor profile should have been created for the existing user
    expect(Doctor::withoutGlobalScopes()->where('user_id', $existing->id)->exists())->toBeFalse();
});

it('POST /doctors returns 403 for a doctor-role user', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('doctors.store'), drStorePayload())
        ->assertForbidden();
});

it('POST /doctors flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('doctors.store'), drStorePayload(['email' => 'toast.test@example.com']))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// DELETE /doctors/{doctor} — remove
// ---------------------------------------------------------------------------

it('owner can soft-delete a doctor via DELETE /doctors/{doctor}', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->delete(route('doctors.destroy', $doctor))
        ->assertRedirect();

    expect(Doctor::withoutGlobalScopes()->find($doctor->id)->deleted_at)->not->toBeNull();
});

it('deleting a doctor leaves the underlying user record intact', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->delete(route('doctors.destroy', $doctor))
        ->assertRedirect();

    expect(User::find($doctorUser->id))->not->toBeNull();
});

it('deleting a doctor revokes the clinic-scoped doctor role', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->delete(route('doctors.destroy', $doctor))
        ->assertRedirect();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinic->id);
    expect($doctorUser->fresh()->hasRole('doctor'))->toBeFalse();
});

it('doctor role gets 403 on DELETE /doctors/{doctor}', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUserA = User::factory()->create();
    $doctorUserB = User::factory()->create();
    drTestRole($doctorUserA, 'doctor', $clinic->id);
    drTestRole($doctorUserB, 'doctor', $clinic->id);

    $doctorB = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUserB->id]);

    $this->actingAs($doctorUserA)
        ->delete(route('doctors.destroy', $doctorB))
        ->assertForbidden();
});

it('DELETE /doctors/{doctor} redirects to the doctors index with a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->delete(route('doctors.destroy', $doctor))
        ->assertRedirect(route('doctors.index'))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// DELETE /doctors/{doctor} — shares offboard's guards (self, future appointments)
// ---------------------------------------------------------------------------

/**
 * Create a future Confirmed appointment for a doctor in a given clinic.
 */
function drFutureAppt(Clinic $clinic, Doctor $doctor): Appointment
{
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return Appointment::factory()->withStatus(AppointmentStatus::Confirmed)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
    ]);
}

it('a doctor with a future appointment cannot be removed via DELETE — message states the count', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    drFutureAppt($clinic, $doctor);
    drFutureAppt($clinic, $doctor);

    $this->actingAs($owner)
        ->delete(route('doctors.destroy', $doctor))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toasts');

    $toasts = session('toasts');
    expect($toasts)->toHaveCount(1)
        ->and($toasts[0]['severity'])->toBe('warn')
        ->and($toasts[0]['summary'])->toContain('2');
    expect(Doctor::withoutGlobalScopes()->find($doctor->id)->deleted_at)->toBeNull();
});

it('an actor cannot remove their own doctor profile via DELETE', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);

    $ownDoctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $owner->id]);

    $this->actingAs($owner)
        ->delete(route('doctors.destroy', $ownDoctor))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toasts');

    expect(Doctor::withoutGlobalScopes()->find($ownDoctor->id)->deleted_at)->toBeNull();
});

it('a doctor with no future appointments can be removed via DELETE', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($owner)
        ->delete(route('doctors.destroy', $doctor))
        ->assertRedirect(route('doctors.index'));

    expect(Doctor::withoutGlobalScopes()->find($doctor->id)->deleted_at)->not->toBeNull();
});

it('an already-offboarded doctor with no future appointments can still be removed via DELETE', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($owner, 'owner', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
        'is_active' => false,
        'left_at' => now()->subDay(),
    ]);

    $this->actingAs($owner)
        ->delete(route('doctors.destroy', $doctor))
        ->assertRedirect(route('doctors.index'));

    expect(Doctor::withoutGlobalScopes()->find($doctor->id)->deleted_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Manager role — may manage doctors, but not self-create
// ---------------------------------------------------------------------------

it('manager can access the create page', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    drTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('doctors.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('doctors/Create'));
});

it('manager can add a new doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    drTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('doctors.store'), drStorePayload(['email' => 'mgr.added@example.com']))
        ->assertRedirect();

    $newUser = User::where('email', 'mgr.added@example.com')->first();
    expect($newUser)->not->toBeNull()
        ->and(Doctor::withoutGlobalScopes()->where('user_id', $newUser->id)->exists())->toBeTrue();
});

it('manager can update any doctor profile', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($manager, 'manager', $clinic->id);

    $doctor = Doctor::factory()->create([
        'clinic_id' => $clinic->id,
        'user_id' => $doctorUser->id,
        'title' => null,
    ]);

    $this->actingAs($manager)
        ->put(route('doctors.update', $doctor), drUpdatePayload(['title' => 'Uzm. Dr.']))
        ->assertRedirect();

    expect($doctor->fresh()->title)->toBe('Uzm. Dr.');
});

it('manager can soft-delete a doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    $doctorUser = User::factory()->create();
    drTestRole($manager, 'manager', $clinic->id);
    drTestRole($doctorUser, 'doctor', $clinic->id);

    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $this->actingAs($manager)
        ->delete(route('doctors.destroy', $doctor))
        ->assertRedirect();

    expect(Doctor::withoutGlobalScopes()->find($doctor->id)->deleted_at)->not->toBeNull();
});

it('manager gets 403 on POST /doctors/self (self-create is owner-only)', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    drTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('doctors.storeOwn'))
        ->assertForbidden();

    expect(Doctor::withoutGlobalScopes()->where('user_id', $manager->id)->exists())->toBeFalse();
});

// ---------------------------------------------------------------------------
// First-login password_reminder
// ---------------------------------------------------------------------------

it('first login flashes password_reminder when last_login_at was null', function (): void {
    $user = User::factory()->create(['last_login_at' => null]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect(session('password_reminder'))->toBeTrue();
});

it('subsequent login does not flash password_reminder when last_login_at is already set', function (): void {
    $user = User::factory()->create(['last_login_at' => now()->subDay()]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ]);

    expect(session('password_reminder'))->toBeNull();
});
