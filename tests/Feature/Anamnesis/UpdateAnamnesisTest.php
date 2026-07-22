<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\PodiatryAnamnesis;
use App\Models\User;
use App\Models\Vertical;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role (anamnesis tests).
 */
function anamAssignRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * A podiatry-vertical clinic + patient (assertVerticalMatch requires the podiatry slug).
 *
 * @return array{clinic: Clinic, patient: Patient}
 */
function uaSetup(): array
{
    $vertical = Vertical::factory()->podiatry()->create();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    return compact('clinic', 'patient');
}

// ---------------------------------------------------------------------------
// Permission seeding
// ---------------------------------------------------------------------------

it('anamnesis.update is seeded onto doctor, assistant, receptionist', function (): void {
    foreach (['doctor', 'assistant', 'receptionist'] as $roleName) {
        $role = Role::findByName($roleName, 'web');
        expect($role->permissions->pluck('name'))->toContain('anamnesis.update');
    }
});

it('anamnesis.update is NOT seeded onto owner or manager (brief-literal role set)', function (): void {
    foreach (['owner', 'manager'] as $roleName) {
        $role = Role::findByName($roleName, 'web');
        expect($role->permissions->pluck('name'))->not->toContain('anamnesis.update');
    }
});

// ---------------------------------------------------------------------------
// First save — lazy get-or-create + morph link
// ---------------------------------------------------------------------------

it('first PUT lazily creates the PodiatryAnamnesis row and links it via the morph columns', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    expect($setup['patient']->anamnesis_id)->toBeNull();

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [
            'blood_type' => 'A+',
            'height_cm' => 170,
            'weight_kg' => 65.5,
            'hypertension' => true,
        ])
        ->assertRedirect();

    $fresh = $setup['patient']->fresh();
    expect($fresh->anamnesis_type)->toBe('podiatry_anamnesis')
        ->and($fresh->anamnesis_id)->not->toBeNull();

    $detail = $fresh->anamnesis;
    expect($detail)->toBeInstanceOf(PodiatryAnamnesis::class)
        ->and($detail->blood_type)->toBe('A+')
        ->and($detail->height_cm)->toBe(170)
        ->and((float) $detail->weight_kg)->toBe(65.5)
        ->and($detail->hypertension)->toBeTrue();
});

it('a second PUT updates the same row rather than creating a new one', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();

    $firstDetailId = $setup['patient']->fresh()->anamnesis_id;

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'B-'])
        ->assertRedirect();

    $fresh = $setup['patient']->fresh();
    expect($fresh->anamnesis_id)->toBe($firstDetailId)
        ->and($fresh->anamnesis->blood_type)->toBe('B-')
        ->and(PodiatryAnamnesis::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// Authorization — doctor/assistant/receptionist allowed, others 403
// ---------------------------------------------------------------------------

it('doctor can PUT the anamnesis', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();
});

it('assistant can PUT the anamnesis', function (): void {
    $setup = uaSetup();
    $assistant = User::factory()->create();
    anamAssignRole($assistant, 'assistant', $setup['clinic']->id);

    $this->actingAs($assistant)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();
});

it('receptionist can PUT the anamnesis', function (): void {
    $setup = uaSetup();
    $receptionist = User::factory()->create();
    anamAssignRole($receptionist, 'receptionist', $setup['clinic']->id);

    $this->actingAs($receptionist)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect();
});

it('owner (lacks anamnesis.update) gets 403 on PUT', function (): void {
    $setup = uaSetup();
    $owner = User::factory()->create();
    anamAssignRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertForbidden();

    expect($setup['patient']->fresh()->anamnesis_id)->toBeNull();
});

it('guest is redirected to login on PUT', function (): void {
    $setup = uaSetup();

    $this->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'A+'])
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// Validation
// ---------------------------------------------------------------------------

it('an invalid blood_type value fails validation (422)', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'Z+'])
        ->assertSessionHasErrors('blood_type');
});

it('an out-of-range height_cm fails validation (422)', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['height_cm' => 301])
        ->assertSessionHasErrors('height_cm');
});

it('an empty payload (all-null form) is accepted — every field is nullable', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), [])
        ->assertSessionHasNoErrors()
        ->assertRedirect();
});

// ---------------------------------------------------------------------------
// Vertical guard
// ---------------------------------------------------------------------------

it('a non-podiatry clinic gets a validation error on PUT (vertical mismatch)', function (): void {
    $otherVertical = Vertical::factory()->create(['slug' => 'dermatology']);
    $clinic = Clinic::factory()->create(['vertical_id' => $otherVertical->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $patient), ['blood_type' => 'A+'])
        ->assertSessionHasErrors('anamnesis');
});

// ---------------------------------------------------------------------------
// Inertia prop contract — patients.show / treatments.process expose `anamnesis`
// ---------------------------------------------------------------------------

it('patients.show exposes anamnesis=null before any save', function (): void {
    $setup = uaSetup();
    $owner = User::factory()->create();
    anamAssignRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->get(route('patients.show', $setup['patient']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->where('anamnesis', null));
});

it('patients.show exposes the filled anamnesis fields after a save', function (): void {
    $setup = uaSetup();
    $doctor = User::factory()->create();
    anamAssignRole($doctor, 'doctor', $setup['clinic']->id);

    $this->actingAs($doctor)
        ->put(route('patients.anamnesis.update', $setup['patient']), ['blood_type' => 'AB-'])
        ->assertRedirect();

    $this->actingAs($doctor)
        ->get(route('patients.show', $setup['patient']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('anamnesis.blood_type', 'AB-'));
});

it('patients.show emits auth.permissions containing anamnesis.update for assistant, not for owner', function (): void {
    $setup = uaSetup();
    $assistant = User::factory()->create();
    anamAssignRole($assistant, 'assistant', $setup['clinic']->id);

    $this->actingAs($assistant)
        ->get(route('patients.show', $setup['patient']))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('anamnesis.update')));

    $owner = User::factory()->create();
    anamAssignRole($owner, 'owner', $setup['clinic']->id);

    $this->actingAs($owner)
        ->get(route('patients.show', $setup['patient']))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('anamnesis.update')));
});
