<?php

use App\Models\Clinic;
use App\Models\Patient;
use App\Models\User;
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
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function pntRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// Permission seeding — patients.note.update is on the right roles
// ---------------------------------------------------------------------------

it('patients.note.update is seeded onto owner, manager, doctor, receptionist', function (): void {
    foreach (['owner', 'manager', 'doctor', 'receptionist'] as $roleName) {
        $role = Role::findByName($roleName, 'web');
        expect($role->permissions->pluck('name'))->toContain('patients.note.update');
    }
});

it('patients.note.update is NOT seeded onto assistant', function (): void {
    $role = Role::findByName('assistant', 'web');
    expect($role->permissions->pluck('name'))->not->toContain('patients.note.update');
});

// ---------------------------------------------------------------------------
// GET /patients/{patient} — Show emits canEditNotes prop
// ---------------------------------------------------------------------------

it('show emits canEditNotes=true for owner', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pntRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->where('auth.permissions', fn ($p) => $p->contains('patients.note.update'))
        );
});

it('show emits canEditNotes=true for doctor', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    pntRole($doctor, 'doctor', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($doctor)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('patients.note.update')));
});

it('show emits canEditNotes=true for receptionist', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    pntRole($receptionist, 'receptionist', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($receptionist)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('patients.note.update')));
});

it('show emits canEditNotes=false for assistant', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    pntRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('patients.note.update')));
});

it('note-edit and update are independent permissions (assistant has neither)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    pntRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->get(route('patients.show', $patient))
        ->assertInertia(fn ($page) => $page
            ->where('auth.permissions', fn ($p) => ! $p->contains('patients.update')
                && ! $p->contains('patients.note.update'))
        );
});

// ---------------------------------------------------------------------------
// PATCH /patients/{patient}/notes — authorized roles can update
// ---------------------------------------------------------------------------

it('owner can PATCH notes and is redirected to patients.show', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pntRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'notes' => 'old note']);

    $this->actingAs($owner)
        ->patch(route('patients.notes.update', $patient), ['notes' => 'updated note'])
        ->assertRedirect(route('patients.show', $patient));

    expect($patient->fresh()->notes)->toBe('updated note');
});

it('doctor can PATCH notes and the value persists', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    pntRole($doctor, 'doctor', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'notes' => null]);

    $this->actingAs($doctor)
        ->patch(route('patients.notes.update', $patient), ['notes' => 'doctor note'])
        ->assertRedirect();

    expect($patient->fresh()->notes)->toBe('doctor note');
});

it('manager can PATCH notes', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    pntRole($manager, 'manager', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($manager)
        ->patch(route('patients.notes.update', $patient), ['notes' => 'manager note'])
        ->assertRedirect();

    expect($patient->fresh()->notes)->toBe('manager note');
});

it('receptionist can PATCH notes', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    pntRole($receptionist, 'receptionist', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($receptionist)
        ->patch(route('patients.notes.update', $patient), ['notes' => 'receptionist note'])
        ->assertRedirect();

    expect($patient->fresh()->notes)->toBe('receptionist note');
});

it('updateNotes flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pntRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->patch(route('patients.notes.update', $patient), ['notes' => 'some note'])
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// PATCH /patients/{patient}/notes — clearing to empty is allowed
// ---------------------------------------------------------------------------

it('clearing notes to null is accepted and persists', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pntRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'notes' => 'had a note']);

    $this->actingAs($owner)
        ->patch(route('patients.notes.update', $patient), ['notes' => null])
        ->assertRedirect();

    expect($patient->fresh()->notes)->toBeNull();
});

it('clearing notes to empty string persists as null', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pntRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'notes' => 'had a note']);

    $this->actingAs($owner)
        ->patch(route('patients.notes.update', $patient), ['notes' => ''])
        ->assertRedirect();

    expect($patient->fresh()->notes)->toBeNull();
});

it('omitting notes from the payload clears the note to null', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pntRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'notes' => 'had a note']);

    $this->actingAs($owner)
        ->patch(route('patients.notes.update', $patient), [])
        ->assertRedirect();

    expect($patient->fresh()->notes)->toBeNull();
});

// ---------------------------------------------------------------------------
// PATCH /patients/{patient}/notes — validation
// ---------------------------------------------------------------------------

it('notes over 5000 chars fails validation', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pntRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->patch(route('patients.notes.update', $patient), ['notes' => str_repeat('a', 5001)])
        ->assertSessionHasErrors('notes');
});

it('notes at exactly 5000 chars is accepted', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pntRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->patch(route('patients.notes.update', $patient), ['notes' => str_repeat('a', 5000)])
        ->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------------------
// PATCH /patients/{patient}/notes — authorization
// ---------------------------------------------------------------------------

it('assistant gets 403 on PATCH /patients/{patient}/notes', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    pntRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'notes' => 'original']);

    $this->actingAs($assistant)
        ->patch(route('patients.notes.update', $patient), ['notes' => 'hacked'])
        ->assertForbidden();

    expect($patient->fresh()->notes)->toBe('original');
});

it('guest is redirected to login on PATCH /patients/{patient}/notes', function (): void {
    $clinic = Clinic::factory()->create();
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->patch(route('patients.notes.update', $patient), ['notes' => 'hacked'])
        ->assertRedirect(route('login'));
});

// ---------------------------------------------------------------------------
// PATCH /patients/{patient}/notes — multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it('clinic B owner gets 404 on PATCH of a clinic A patient notes', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    pntRole($ownerA, 'owner', $clinicA->id);
    pntRole($ownerB, 'owner', $clinicB->id);

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id, 'notes' => 'clinic A note']);

    $this->actingAs($ownerB)
        ->patch(route('patients.notes.update', $patientA), ['notes' => 'hacked by B'])
        ->assertNotFound();

    expect($patientA->fresh()->notes)->toBe('clinic A note');
});

it('cross-clinic PATCH does not mutate the target patient', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerB = User::factory()->create();
    pntRole($ownerB, 'owner', $clinicB->id);

    $patientA = Patient::factory()->create(['clinic_id' => $clinicA->id, 'notes' => 'untouched']);

    $this->actingAs($ownerB)
        ->patch(route('patients.notes.update', $patientA), ['notes' => 'mutated'])
        ->assertNotFound();

    expect($patientA->fresh()->notes)->toBe('untouched');
});

it('clinic A owner cannot access clinic B patient show page', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    pntRole($ownerA, 'owner', $clinicA->id);

    $patientB = Patient::factory()->create(['clinic_id' => $clinicB->id, 'notes' => 'secret B note']);

    $this->actingAs($ownerA)
        ->get(route('patients.show', $patientB))
        ->assertNotFound();
});
