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
function tagRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// GET /tags — access control + rendering
// ---------------------------------------------------------------------------

it('owner can access GET /tags and the Index component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    Tag::factory()->create(['clinic_id' => $clinic->id, 'name' => 'VIP']);

    $this->actingAs($owner)
        ->get(route('tags.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('tags/Index')
            ->has('tags.data', 1)
            ->where('tags.data.0.name', 'VIP')
        );
});

it('manager can access GET /tags', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    tagRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)->get(route('tags.index'))->assertOk();
});

it('receptionist (patients.update only, no tags.manage) gets 403 on GET /tags', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    tagRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('tags.index'))
        ->assertForbidden();
});

it('assistant gets 403 on GET /tags', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    tagRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('tags.index'))
        ->assertForbidden();
});

it('tags index includes patients_count via withCount', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);
    Patient::factory()->count(2)->create(['clinic_id' => $clinic->id])
        ->each(fn (Patient $p) => $p->tags()->attach($tag));

    $this->actingAs($owner)
        ->get(route('tags.index'))
        ->assertInertia(fn ($page) => $page->where('tags.data.0.patients_count', 2));
});

// ---------------------------------------------------------------------------
// POST /tags — store
// ---------------------------------------------------------------------------

it('owner can create a tag', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('tags.store'), ['name' => 'Diyabetik', 'color' => '#FF0000'])
        ->assertRedirect(route('tags.index'));

    expect(Tag::where('clinic_id', $clinic->id)->where('name', 'Diyabetik')->exists())->toBeTrue();
});

it('store auto-sets clinic_id from ClinicContext', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('tags.store'), ['name' => 'ClinicCheck', 'color' => '#00FF00']);

    $tag = Tag::where('name', 'ClinicCheck')->first();

    expect($tag)->not->toBeNull()
        ->and($tag->clinic_id)->toBe($clinic->id);
});

it('store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('tags.store'), ['name' => 'Toasted', 'color' => '#123ABC'])
        ->assertSessionHas('toasts');
});

it('manager can create a tag', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    tagRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('tags.store'), ['name' => 'ManagerTag', 'color' => '#ABCDEF'])
        ->assertRedirect();

    expect(Tag::where('name', 'ManagerTag')->exists())->toBeTrue();
});

it('receptionist gets 403 on POST /tags (patients.update does not grant tag definition CRUD)', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    tagRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('tags.store'), ['name' => 'ShouldFail', 'color' => '#123456'])
        ->assertForbidden();

    expect(Tag::where('name', 'ShouldFail')->exists())->toBeFalse();
});

it('assistant gets 403 on POST /tags', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    tagRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('tags.store'), ['name' => 'ShouldFail2', 'color' => '#123456'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Validation — store
// ---------------------------------------------------------------------------

it('store rejects a missing name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('tags.store'), ['name' => '', 'color' => '#123456'])
        ->assertSessionHasErrors('name');
});

it('store rejects a name over 50 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('tags.store'), ['name' => str_repeat('a', 51), 'color' => '#123456'])
        ->assertSessionHasErrors('name');
});

it('store rejects a color not matching the hex regex', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('tags.store'), ['name' => 'BadColor', 'color' => 'not-a-color'])
        ->assertSessionHasErrors('color');
});

it('store rejects a duplicate name case-insensitively within the same clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    Tag::factory()->create(['clinic_id' => $clinic->id, 'name' => 'VIP']);

    $this->actingAs($owner)
        ->post(route('tags.store'), ['name' => 'vip', 'color' => '#123456'])
        ->assertSessionHasErrors('name');

    expect(Tag::where('clinic_id', $clinic->id)->count())->toBe(1);
});

it('store allows the same name reused in a different clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    Tag::factory()->create(['clinic_id' => $clinicB->id, 'name' => 'VIP']);

    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinicA->id);

    $this->actingAs($owner)
        ->post(route('tags.store'), ['name' => 'VIP', 'color' => '#123456'])
        ->assertRedirect();

    expect(Tag::where('clinic_id', $clinicA->id)->where('name', 'VIP')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// PUT /tags/{tag} — update
// ---------------------------------------------------------------------------

it('owner can update a tag', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $tag = Tag::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Old']);

    $this->actingAs($owner)
        ->put(route('tags.update', $tag), ['name' => 'New', 'color' => '#000000'])
        ->assertRedirect(route('tags.index'));

    expect($tag->fresh()->name)->toBe('New');
});

it('update rejects a duplicate name case-insensitively, ignoring self', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $tagA = Tag::factory()->create(['clinic_id' => $clinic->id, 'name' => 'VIP']);
    $tagB = Tag::factory()->create(['clinic_id' => $clinic->id, 'name' => 'Loyal']);

    // Renaming self to the same value (different case) must not error (ignore-self).
    $this->actingAs($owner)
        ->put(route('tags.update', $tagA), ['name' => 'vip', 'color' => '#123456'])
        ->assertSessionHasNoErrors();

    // Renaming to another tag's name (case-insensitive) must error.
    $this->actingAs($owner)
        ->put(route('tags.update', $tagB), ['name' => 'VIP', 'color' => '#123456'])
        ->assertSessionHasErrors('name');
});

it('receptionist gets 403 on PUT /tags/{tag}', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    tagRole($receptionist, 'receptionist', $clinic->id);

    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($receptionist)
        ->put(route('tags.update', $tag), ['name' => 'Hacked', 'color' => '#123456'])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// DELETE /tags/{tag}
// ---------------------------------------------------------------------------

it('owner can delete a tag', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->delete(route('tags.destroy', $tag))
        ->assertRedirect(route('tags.index'));

    expect(Tag::find($tag->id))->toBeNull();
});

it('deleting a tag detaches it from patients (pivot cascade)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);
    $patient->tags()->attach($tag);

    $this->actingAs($owner)->delete(route('tags.destroy', $tag));

    expect($patient->tags()->count())->toBe(0);
});

it('assistant gets 403 on DELETE /tags/{tag}', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    tagRole($assistant, 'assistant', $clinic->id);

    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->delete(route('tags.destroy', $tag))
        ->assertForbidden();

    expect(Tag::find($tag->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation
// ---------------------------------------------------------------------------

it("clinic A's tags index never exposes clinic B's tags", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    tagRole($ownerA, 'owner', $clinicA->id);

    Tag::factory()->create(['clinic_id' => $clinicB->id, 'name' => 'Clinic B Tag']);

    $this->actingAs($ownerA)
        ->get(route('tags.index'))
        ->assertInertia(fn ($page) => $page->has('tags.data', 0));
});

it('clinic A owner gets 404 on PUT /tags/{tag} for a clinic B tag', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    tagRole($ownerA, 'owner', $clinicA->id);

    $tagB = Tag::factory()->create(['clinic_id' => $clinicB->id, 'name' => 'Original']);

    $this->actingAs($ownerA)
        ->put(route('tags.update', $tagB), ['name' => 'Hacked', 'color' => '#000000'])
        ->assertNotFound();

    expect($tagB->fresh()->name)->toBe('Original');
});

it('clinic A owner gets 404 on DELETE /tags/{tag} for a clinic B tag', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    tagRole($ownerA, 'owner', $clinicA->id);

    $tagB = Tag::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->delete(route('tags.destroy', $tagB))
        ->assertNotFound();

    expect(Tag::withoutGlobalScopes()->find($tagB->id))->not->toBeNull();
});

it("clinic A's tag name uniqueness check never collides with clinic B's tag name", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    Tag::factory()->create(['clinic_id' => $clinicB->id, 'name' => 'Shared']);

    $ownerA = User::factory()->create();
    tagRole($ownerA, 'owner', $clinicA->id);

    $this->actingAs($ownerA)
        ->post(route('tags.store'), ['name' => 'Shared', 'color' => '#123456'])
        ->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------------------
// GET /tags?edit=<id> — the list's edit-dialog deep link
// ---------------------------------------------------------------------------

it('resolves ?edit into an editing prop for a manager', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    tagRole($manager, 'manager', $clinic->id);

    $tag = Tag::factory()->create(['clinic_id' => $clinic->id, 'name' => 'VIP', 'color' => '#0D9488']);

    $this->actingAs($manager)
        ->get(route('tags.index', ['edit' => $tag->id]))
        ->assertInertia(fn ($page) => $page
            ->where('editing.id', $tag->id)
            ->where('editing.name', 'VIP')
            ->where('editing.color', '#0D9488')
        );
});

it('leaves editing null without ?edit and for another clinic\'s tag', function (): void {
    $clinic = Clinic::factory()->create();
    $other = Clinic::factory()->create();
    $owner = User::factory()->create();
    tagRole($owner, 'owner', $clinic->id);

    $foreign = Tag::factory()->create(['clinic_id' => $other->id]);

    $this->actingAs($owner)
        ->get(route('tags.index'))
        ->assertInertia(fn ($page) => $page->where('editing', null));

    $this->actingAs($owner)
        ->get(route('tags.index', ['edit' => $foreign->id]))
        ->assertInertia(fn ($page) => $page->where('editing', null));
});
