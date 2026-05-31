<?php

use App\Models\Clinic;
use App\Models\Doctor;
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
function drIsoRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Two-tenant fixture: returns [$clinicA, $ownerA, $clinicB, $doctorFromB].
 *
 * Owner A belongs to clinic A; the doctor belongs to clinic B.
 *
 * @return array{0: Clinic, 1: User, 2: Clinic, 3: Doctor}
 */
function twoTenantFixture(): array
{
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    drIsoRole($ownerA, 'owner', $clinicA->id);

    $userB = User::factory()->create();
    $doctorB = Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $userB->id]);

    return [$clinicA, $ownerA, $clinicB, $doctorB];
}

// ---------------------------------------------------------------------------
// GET /doctors — index read isolation
// ---------------------------------------------------------------------------

it("clinic A's index never exposes clinic B's doctors", function (): void {
    [$clinicA, $ownerA, $clinicB, $doctorB] = twoTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('doctors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('doctors', 0));
});

it("clinic A's index does not contain clinic B's doctor by name", function (): void {
    [$clinicA, $ownerA, $clinicB, $doctorB] = twoTenantFixture();

    $nameB = $doctorB->user->name;

    $this->actingAs($ownerA)
        ->get(route('doctors.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('doctors', 0)
        );

    // also assert response body doesn't contain clinic B's name
    $response = $this->actingAs($ownerA)->get(route('doctors.index'));
    expect($response->content())->not->toContain($nameB);
});

// ---------------------------------------------------------------------------
// GET /doctors/{doctor}/edit — cross-clinic edit is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on GET /doctors/{doctor}/edit for a clinic B doctor', function (): void {
    [$clinicA, $ownerA, $clinicB, $doctorB] = twoTenantFixture();

    $this->actingAs($ownerA)
        ->get(route('doctors.edit', $doctorB))
        ->assertNotFound();
});

// ---------------------------------------------------------------------------
// PUT /doctors/{doctor} — cross-clinic update is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on PUT /doctors/{doctor} for a clinic B doctor', function (): void {
    [$clinicA, $ownerA, $clinicB, $doctorB] = twoTenantFixture();

    $originalSpecialization = $doctorB->specialization;

    $this->actingAs($ownerA)
        ->put(route('doctors.update', $doctorB), [
            'first_name' => 'Hacker',
            'last_name' => 'User',
            'title' => 'Dr.',
            'specialization' => 'Hacked',
            'bio' => null,
            'license_number' => null,
            'certificate' => null,
            'is_active' => true,
        ])
        ->assertNotFound();

    // Clinic B's doctor must be unchanged
    expect($doctorB->fresh()->specialization)->toBe($originalSpecialization);
});

// ---------------------------------------------------------------------------
// POST /doctors/{doctor}/avatar — cross-clinic avatar upload is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on POST /doctors/{doctor}/avatar for a clinic B doctor', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    [$clinicA, $ownerA, $clinicB, $doctorB] = twoTenantFixture();

    $this->actingAs($ownerA)
        ->post(route('doctors.avatar.update', $doctorB), [
            'image' => UploadedFile::fake()->image('avatar.jpg', 300, 300),
        ])
        ->assertNotFound();

    expect($doctorB->fresh()->getMedia('avatar'))->toHaveCount(0);
});

// ---------------------------------------------------------------------------
// DELETE /doctors/{doctor}/avatar — cross-clinic avatar remove is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on DELETE /doctors/{doctor}/avatar for a clinic B doctor', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    [$clinicA, $ownerA, $clinicB, $doctorB] = twoTenantFixture();

    $doctorB->addMedia(UploadedFile::fake()->image('avatar.jpg', 300, 300))
        ->toMediaCollection('avatar');

    $this->actingAs($ownerA)
        ->delete(route('doctors.avatar.remove', $doctorB))
        ->assertNotFound();

    // Clinic B's doctor avatar must be untouched
    expect($doctorB->fresh()->getMedia('avatar'))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// doctors.mine — does not cross clinic boundary
// ---------------------------------------------------------------------------

it('doctors.mine only resolves the profile within the active clinic context', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $sharedUser = User::factory()->create();
    drIsoRole($sharedUser, 'doctor', $clinicA->id);

    // Doctor profile exists at clinic B only (not at clinic A)
    Doctor::factory()->create(['clinic_id' => $clinicB->id, 'user_id' => $sharedUser->id]);

    // Acting as the user whose clinic context resolves to clinic A
    // → no profile at clinic A → redirected to index, not rendered
    $this->actingAs($sharedUser)
        ->get(route('doctors.mine'))
        ->assertRedirect(route('doctors.index'));
});

// ---------------------------------------------------------------------------
// DELETE /doctors/{doctor} — cross-clinic destroy is 404
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on DELETE /doctors/{doctor} for a clinic B doctor', function (): void {
    [$clinicA, $ownerA, $clinicB, $doctorB] = twoTenantFixture();

    $this->actingAs($ownerA)
        ->delete(route('doctors.destroy', $doctorB))
        ->assertNotFound();

    // Clinic B's doctor must still exist (not soft-deleted)
    expect(Doctor::withoutGlobalScopes()->find($doctorB->id)->deleted_at)->toBeNull();
});

// ---------------------------------------------------------------------------
// addDoctor — role is scoped to the active clinic only
// ---------------------------------------------------------------------------

it("an added doctor's doctor role is scoped to the active clinic only and absent from clinic B", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    drIsoRole($ownerA, 'owner', $clinicA->id);

    $this->actingAs($ownerA)
        ->post(route('doctors.store'), [
            'first_name' => 'Scoped',
            'last_name' => 'Doctor',
            'email' => 'scoped.doc@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'title' => null,
            'specialization' => null,
            'bio' => null,
            'license_number' => null,
            'certificate' => null,
            'is_active' => true,
        ])
        ->assertRedirect();

    $newUser = User::where('email', 'scoped.doc@example.com')->first();
    expect($newUser)->not->toBeNull();

    // Has the role at clinic A
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicA->id);
    expect($newUser->fresh()->hasRole('doctor'))->toBeTrue();

    // Does NOT have the role at clinic B
    $newUser->unsetRelation('roles')->unsetRelation('permissions');
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicB->id);
    expect($newUser->fresh()->hasRole('doctor'))->toBeFalse();
});
