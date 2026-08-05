<?php

use App\Models\Clinic;
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
 * Assign a clinic-scoped role to a user (Spatie Teams: clinic_id on assignment).
 */
function clinicRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a valid PUT /clinic payload seeded from a clinic's existing data.
 *
 * @return array<string, mixed>
 */
function clinicUpdatePayload(Clinic $clinic): array
{
    return [
        'name' => 'Updated Clinic',
        'slug' => 'updated-clinic-'.$clinic->id,
        'description' => 'A fine test clinic.',
        'phone' => null,
        'email' => 'info@testclinic.example',
        'website' => null,
        'country_id' => $clinic->country_id,
        'city_id' => $clinic->city_id,
        'district' => null,
        'address' => null,
        'postal_code' => null,
        'default_slot_duration_minutes' => 45,
        'auto_no_show_enabled' => true,
        'auto_no_show_grace_hours' => 2,
        'working_hours' => Clinic::defaultWorkingHours(),
    ];
}

// ---------------------------------------------------------------------------
// GET /clinic — access control
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /clinic', function (): void {
    $this->get(route('clinic.edit'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /clinic and sees the clinic/Edit Inertia component', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'My Clinic']);
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clinic/Edit')
            ->where('clinic.name', 'My Clinic')
            ->where('clinic.id', $clinic->id)
        );
});

it('GET /clinic props include countries, cities, vertical, and image URL keys', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('countries')
            ->has('cities')
            ->has('vertical')
            ->has('clinic.logo_url')
            ->has('clinic.cover_url')
            ->has('clinic.cover_mobile_url')
            ->has('clinic.working_hours')
            ->has('clinic.default_slot_duration_minutes')
        );
});

it('shares the active clinic identity (id, name, logo_url) with the app shell', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'Shell Clinic']);
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('activeClinic.id', $clinic->id)
            ->where('activeClinic.name', 'Shell Clinic')
            ->where('activeClinic.logo_url', null)
        );
});

it('a doctor of the same clinic gets 403 on GET /clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    clinicRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->get(route('clinic.edit'))
        ->assertForbidden();
});

it('a manager can GET /clinic and edit the profile', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    clinicRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canEditClinic', true));
});

// ---------------------------------------------------------------------------
// PUT /clinic — happy path
// ---------------------------------------------------------------------------

it('owner can update the clinic profile and the DB reflects the change', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'Original Name']);
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.update'), clinicUpdatePayload($clinic))
        ->assertRedirect();

    $fresh = $clinic->fresh();
    expect($fresh->name)->toBe('Updated Clinic')
        ->and($fresh->slug)->toBe('updated-clinic-'.$clinic->id)
        ->and($fresh->email)->toBe('info@testclinic.example')
        ->and($fresh->default_slot_duration_minutes)->toBe(45);
});

it('successful PUT /clinic flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.update'), clinicUpdatePayload($clinic))
        ->assertSessionHas('toasts');
});

it('PUT /clinic persists updated working_hours with a closed day', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'saturday' => ['closed' => true],
    ]);
    $payload = array_merge(clinicUpdatePayload($clinic), ['working_hours' => $hours]);

    $this->actingAs($owner)
        ->put(route('clinic.update'), $payload)
        ->assertRedirect();

    expect($clinic->fresh()->working_hours['saturday'])->toBe(['closed' => true]);
});

it('PUT /clinic persists updated default_slot_duration_minutes', function (): void {
    $clinic = Clinic::factory()->create(['default_slot_duration_minutes' => 30]);
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $payload = array_merge(clinicUpdatePayload($clinic), ['default_slot_duration_minutes' => 60]);

    $this->actingAs($owner)
        ->put(route('clinic.update'), $payload)
        ->assertRedirect();

    expect($clinic->fresh()->default_slot_duration_minutes)->toBe(60);
});

// ---------------------------------------------------------------------------
// PUT /clinic — validation failures
// ---------------------------------------------------------------------------

it('rejects PUT /clinic when name is missing and leaves the DB unchanged', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'Original Name']);
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $payload = array_merge(clinicUpdatePayload($clinic), ['name' => '']);

    $this->actingAs($owner)
        ->put(route('clinic.update'), $payload)
        ->assertSessionHasErrors('name');

    expect($clinic->fresh()->name)->toBe('Original Name');
});

it('rejects PUT /clinic with a slug already used by another clinic', function (): void {
    Clinic::factory()->create(['slug' => 'taken-slug']);
    $clinicB = Clinic::factory()->create(['slug' => 'clinic-b-slug']);

    $ownerB = User::factory()->create();
    clinicRole($ownerB, 'owner', $clinicB->id);

    $payload = array_merge(clinicUpdatePayload($clinicB), ['slug' => 'taken-slug']);

    $this->actingAs($ownerB)
        ->put(route('clinic.update'), $payload)
        ->assertSessionHasErrors('slug');

    expect($clinicB->fresh()->slug)->toBe('clinic-b-slug');
});

it('allows PUT /clinic with the same slug as the current clinic (ignores own row)', function (): void {
    $clinic = Clinic::factory()->create(['slug' => 'my-own-slug']);
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $payload = array_merge(clinicUpdatePayload($clinic), ['slug' => 'my-own-slug']);

    $this->actingAs($owner)
        ->put(route('clinic.update'), $payload)
        ->assertRedirect();

    expect($clinic->fresh()->slug)->toBe('my-own-slug');
});

it('rejects PUT /clinic when working_hours.monday has close <= open', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '17:00', 'close' => '09:00', 'break' => null],
    ]);
    $payload = array_merge(clinicUpdatePayload($clinic), ['working_hours' => $hours]);

    $this->actingAs($owner)
        ->put(route('clinic.update'), $payload)
        ->assertSessionHasErrors('working_hours.monday');
});

it('rejects PUT /clinic when working_hours break falls outside opening hours', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $hours = array_merge(Clinic::defaultWorkingHours(), [
        'monday' => ['open' => '09:00', 'close' => '17:00', 'break' => ['07:00', '08:00']],
    ]);
    $payload = array_merge(clinicUpdatePayload($clinic), ['working_hours' => $hours]);

    $this->actingAs($owner)
        ->put(route('clinic.update'), $payload)
        ->assertSessionHasErrors('working_hours.monday');
});

it('rejects PUT /clinic when working_hours has fewer than 7 day keys', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $incompleteHours = array_slice(Clinic::defaultWorkingHours(), 0, 5, true);
    $payload = array_merge(clinicUpdatePayload($clinic), ['working_hours' => $incompleteHours]);

    $this->actingAs($owner)
        ->put(route('clinic.update'), $payload)
        ->assertSessionHasErrors('working_hours');
});

it('rejects PUT /clinic when working_hours is missing entirely', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $payload = clinicUpdatePayload($clinic);
    unset($payload['working_hours']);

    $this->actingAs($owner)
        ->put(route('clinic.update'), $payload)
        ->assertSessionHasErrors('working_hours');
});

it('a doctor of the same clinic gets 403 on PUT /clinic', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'Original Name']);
    $doctor = User::factory()->create();
    clinicRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->put(route('clinic.update'), clinicUpdatePayload($clinic))
        ->assertForbidden();

    expect($clinic->fresh()->name)->toBe('Original Name');
});

it('a manager can PUT /clinic and the change is persisted', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'Original Name']);
    $manager = User::factory()->create();
    clinicRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->put(route('clinic.update'), [...clinicUpdatePayload($clinic), 'name' => 'Manager Edited'])
        ->assertRedirect();

    expect($clinic->fresh()->name)->toBe('Manager Edited');
});

// ---------------------------------------------------------------------------
// POST /clinic/media/{collection} — upload happy path
// ---------------------------------------------------------------------------

it('owner can upload a logo and the collection has exactly one item', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'logo']), [
            'image' => UploadedFile::fake()->image('logo.png', 256, 256),
        ])
        ->assertRedirect();

    expect($clinic->fresh()->getMedia('logo'))->toHaveCount(1);
});

it('re-uploading a logo replaces the previous one (single-file collection)', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'logo']), [
            'image' => UploadedFile::fake()->image('first.png', 256, 256),
        ])
        ->assertRedirect();

    app(ClinicContext::class)->forget();

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'logo']), [
            'image' => UploadedFile::fake()->image('second.png', 256, 256),
        ])
        ->assertRedirect();

    expect($clinic->fresh()->getMedia('logo'))->toHaveCount(1);
});

it('owner can remove a logo and the collection is empty afterwards', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $clinic->addMedia(UploadedFile::fake()->image('logo.png', 256, 256))
        ->toMediaCollection('logo');
    expect($clinic->getMedia('logo'))->toHaveCount(1);

    $this->actingAs($owner)
        ->delete(route('clinic.media.remove', ['collection' => 'logo']))
        ->assertRedirect();

    expect($clinic->fresh()->getMedia('logo'))->toHaveCount(0);
});

it('owner can upload a cover image meeting the 1920×1080 minimum', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'cover']), [
            'image' => UploadedFile::fake()->image('cover.jpg', 1920, 1080),
        ])
        ->assertRedirect();

    expect($clinic->fresh()->getMedia('cover'))->toHaveCount(1);
});

it('owner can upload a cover_mobile image meeting the 1440×1440 minimum', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'cover_mobile']), [
            'image' => UploadedFile::fake()->image('cover_mobile.jpg', 1440, 1440),
        ])
        ->assertRedirect();

    expect($clinic->fresh()->getMedia('cover_mobile'))->toHaveCount(1);
});

it('successful media upload flashes a success toast', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'logo']), [
            'image' => UploadedFile::fake()->image('logo.png', 256, 256),
        ])
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// POST /clinic/media — validation failures (no media stored)
// ---------------------------------------------------------------------------

it('rejects an SVG file upload and stores nothing', function (): void {
    Storage::fake(config('media-library.disk_name'));

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $svg = UploadedFile::fake()->createWithContent(
        'logo.svg',
        '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
    );

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'logo']), ['image' => $svg])
        ->assertSessionHasErrors('image');

    expect($clinic->fresh()->getMedia('logo'))->toHaveCount(0);
});

it('rejects a cover image that is too small (800×450 < min 1920×1080)', function (): void {
    Storage::fake(config('media-library.disk_name'));

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'cover']), [
            'image' => UploadedFile::fake()->image('cover.jpg', 800, 450),
        ])
        ->assertSessionHasErrors('image');

    expect($clinic->fresh()->getMedia('cover'))->toHaveCount(0);
});

it('rejects a cover_mobile image that is too small (500×500 < min 1440×1440)', function (): void {
    Storage::fake(config('media-library.disk_name'));

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'cover_mobile']), [
            'image' => UploadedFile::fake()->image('cover_mobile.jpg', 500, 500),
        ])
        ->assertSessionHasErrors('image');

    expect($clinic->fresh()->getMedia('cover_mobile'))->toHaveCount(0);
});

it('rejects upload when no image field is provided', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'logo']), [])
        ->assertSessionHasErrors('image');
});

it('returns 404 for an unknown media collection', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('clinic.media.update', ['collection' => 'unknown']), [
            'image' => UploadedFile::fake()->image('test.png', 256, 256),
        ])
        ->assertNotFound();
});

it('returns 404 for unknown collection on DELETE media', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->delete(route('clinic.media.remove', ['collection' => 'unknown']))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// POST/DELETE /clinic/media — authorization (non-owner gets 403)
// ---------------------------------------------------------------------------

it('a doctor gets 403 when trying to upload media', function (): void {
    Storage::fake(config('media-library.disk_name'));

    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    clinicRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->post(route('clinic.media.update', ['collection' => 'logo']), [
            'image' => UploadedFile::fake()->image('logo.png', 256, 256),
        ])
        ->assertForbidden();

    expect($clinic->fresh()->getMedia('logo'))->toHaveCount(0);
});

it('a doctor gets 403 when trying to remove media', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    clinicRole($doctor, 'doctor', $clinic->id);

    $clinic->addMedia(UploadedFile::fake()->image('logo.png', 256, 256))
        ->toMediaCollection('logo');

    $this->actingAs($doctor)
        ->delete(route('clinic.media.remove', ['collection' => 'logo']))
        ->assertForbidden();

    expect($clinic->fresh()->getMedia('logo'))->toHaveCount(1);
});
