<?php

use App\Models\Clinic;
use App\Models\PatientSegment;
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
function segRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// POST /patient-segments — store
// ---------------------------------------------------------------------------

it('owner can save the queryable filter subset as a segment', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinic->id);
    $tag = Tag::factory()->create(['clinic_id' => $clinic->id]);

    $criteria = [
        'gender' => 'female',
        'is_legacy' => true,
        'tags' => [$tag->id],
        'last_visit_after' => '2026-01-01',
    ];

    $this->actingAs($owner)
        ->post(route('patient-segments.store'), ['name' => 'Lapsed VIP', 'criteria' => $criteria])
        ->assertRedirect();

    $segment = PatientSegment::where('clinic_id', $clinic->id)->where('name', 'Lapsed VIP')->first();

    expect($segment)->not->toBeNull()
        ->and($segment->criteria['gender'])->toBe('female')
        ->and($segment->criteria['is_legacy'])->toBeTrue()
        ->and($segment->criteria['tags'])->toBe([$tag->id])
        ->and($segment->criteria['last_visit_after'])->toBe('2026-01-01');
});

it('store auto-sets clinic_id from ClinicContext', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patient-segments.store'), ['name' => 'ClinicCheck', 'criteria' => ['is_legacy' => true]]);

    $segment = PatientSegment::where('name', 'ClinicCheck')->first();

    expect($segment)->not->toBeNull()
        ->and($segment->clinic_id)->toBe($clinic->id);
});

it('store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patient-segments.store'), ['name' => 'Toasted', 'criteria' => ['is_legacy' => false]])
        ->assertSessionHas('toasts');
});

it('manager can save a segment', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    segRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('patient-segments.store'), ['name' => 'ManagerSegment', 'criteria' => ['is_legacy' => true]])
        ->assertRedirect();

    expect(PatientSegment::where('name', 'ManagerSegment')->exists())->toBeTrue();
});

it('receptionist gets 403 on POST /patient-segments', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    segRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('patient-segments.store'), ['name' => 'ShouldFail', 'criteria' => ['is_legacy' => true]])
        ->assertForbidden();

    expect(PatientSegment::where('name', 'ShouldFail')->exists())->toBeFalse();
});

it('assistant gets 403 on POST /patient-segments', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    segRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('patient-segments.store'), ['name' => 'ShouldFail2', 'criteria' => ['is_legacy' => true]])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Validation — store
// ---------------------------------------------------------------------------

it('store rejects a missing name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patient-segments.store'), ['name' => '', 'criteria' => ['is_legacy' => true]])
        ->assertSessionHasErrors('name');
});

it('store rejects a missing criteria', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patient-segments.store'), ['name' => 'NoCriteria'])
        ->assertSessionHasErrors('criteria');
});

it('store rejects a tag id in criteria that does not belong to the active clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $tagB = Tag::factory()->create(['clinic_id' => $clinicB->id]);

    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinicA->id);

    $this->actingAs($owner)
        ->post(route('patient-segments.store'), [
            'name' => 'ForeignTag',
            'criteria' => ['tags' => [$tagB->id]],
        ])
        ->assertSessionHasErrors('criteria.tags.0');

    expect(PatientSegment::where('name', 'ForeignTag')->exists())->toBeFalse();
});

it('store rejects an invalid gender in criteria', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patient-segments.store'), [
            'name' => 'BadGender',
            'criteria' => ['gender' => 'not-a-gender'],
        ])
        ->assertSessionHasErrors('criteria.gender');
});

// ---------------------------------------------------------------------------
// DELETE /patient-segments/{segment}
// ---------------------------------------------------------------------------

it('owner can delete a segment', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinic->id);

    $segment = PatientSegment::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->delete(route('patient-segments.destroy', $segment))
        ->assertRedirect();

    expect(PatientSegment::find($segment->id))->toBeNull();
});

it('destroy flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    segRole($owner, 'owner', $clinic->id);

    $segment = PatientSegment::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->delete(route('patient-segments.destroy', $segment))
        ->assertSessionHas('toasts');
});

it('assistant gets 403 on DELETE /patient-segments/{segment}', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    segRole($assistant, 'assistant', $clinic->id);

    $segment = PatientSegment::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->delete(route('patient-segments.destroy', $segment))
        ->assertForbidden();

    expect(PatientSegment::find($segment->id))->not->toBeNull();
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation
// ---------------------------------------------------------------------------

it('clinic A owner gets 404 on DELETE /patient-segments/{segment} for a clinic B segment', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    segRole($ownerA, 'owner', $clinicA->id);

    $segmentB = PatientSegment::factory()->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->delete(route('patient-segments.destroy', $segmentB))
        ->assertNotFound();

    expect(PatientSegment::withoutGlobalScopes()->find($segmentB->id))->not->toBeNull();
});

it("a segment created by owner A is never visible in clinic B's patients.index segments prop", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    segRole($ownerA, 'owner', $clinicA->id);
    segRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)
        ->post(route('patient-segments.store'), ['name' => 'Owner A Segment', 'criteria' => ['is_legacy' => true]]);

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($ownerB)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where('segments', []));
});
