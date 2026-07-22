<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\Tag;
use App\Models\User;
use App\Support\ClinicContext;
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
function ptsRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// PUT /patients/{patient}/tags — attach / detach
// ---------------------------------------------------------------------------

it('owner can attach tags to a patient', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptsRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $tagA = Tag::factory()->create(['clinic_id' => $clinic->id]);
    $tagB = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->put(route('patients.tags.sync', $patient), ['tag_ids' => [$tagA->id, $tagB->id]])
        ->assertRedirect(route('patients.show', $patient));

    expect($patient->tags()->pluck('tags.id')->sort()->values()->all())
        ->toBe([$tagA->id, $tagB->id]);
});

it('sync detaches tags omitted from the payload (full replace)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptsRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $tagA = Tag::factory()->create(['clinic_id' => $clinic->id]);
    $tagB = Tag::factory()->create(['clinic_id' => $clinic->id]);
    $patient->tags()->attach([$tagA->id, $tagB->id]);

    $this->actingAs($owner)
        ->put(route('patients.tags.sync', $patient), ['tag_ids' => [$tagA->id]]);

    expect($patient->tags()->pluck('tags.id')->all())->toBe([$tagA->id]);
});

it('sync with an empty tag_ids array detaches all tags', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptsRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);
    $patient->tags()->attach($tag);

    $this->actingAs($owner)
        ->put(route('patients.tags.sync', $patient), ['tag_ids' => []]);

    expect($patient->tags()->count())->toBe(0);
});

it('sync flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptsRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->put(route('patients.tags.sync', $patient), ['tag_ids' => []])
        ->assertSessionHas('toasts');
});

it('receptionist (patients.update, no tags.manage) can attach an existing tag', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    ptsRole($receptionist, 'receptionist', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($receptionist)
        ->put(route('patients.tags.sync', $patient), ['tag_ids' => [$tag->id]])
        ->assertRedirect();

    expect($patient->tags()->pluck('tags.id')->all())->toBe([$tag->id]);
});

it('assistant (no patients.update) gets 403 on PUT /patients/{patient}/tags', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptsRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->put(route('patients.tags.sync', $patient), ['tag_ids' => [$tag->id]])
        ->assertForbidden();

    expect($patient->tags()->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('sync rejects a non-integer tag id', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptsRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->put(route('patients.tags.sync', $patient), ['tag_ids' => ['not-an-id']])
        ->assertSessionHasErrors('tag_ids.0');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation
// ---------------------------------------------------------------------------

it('sync rejects a tag id belonging to another clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $tagB = Tag::factory()->create(['clinic_id' => $clinicB->id]);

    $owner = User::factory()->create();
    ptsRole($owner, 'owner', $clinicA->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinicA->id]);

    $this->actingAs($owner)
        ->put(route('patients.tags.sync', $patient), ['tag_ids' => [$tagB->id]])
        ->assertSessionHasErrors('tag_ids.0');

    expect($patient->tags()->count())->toBe(0);
});

it('clinic A owner gets 404 on PUT /patients/{patient}/tags for a clinic B patient', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    ptsRole($ownerA, 'owner', $clinicA->id);

    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id]);
    $tagB = Tag::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->put(route('patients.tags.sync', $patientB), ['tag_ids' => [$tagB->id]])
        ->assertNotFound();

    expect($patientB->tags()->count())->toBe(0);
});
