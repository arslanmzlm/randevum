<?php

use App\Models\CaseRecord;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
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
 * Assign a clinic-scoped role (case-notes-title tests).
 */
function cntRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a clinic with owner, doctor, patient, and an open case.
 *
 * @return array{clinic: Clinic, owner: User, doctorUser: User, doctor: Doctor, patient: Patient, case: CaseRecord}
 */
function cntSetup(): array
{
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    cntRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    cntRole($doctorUser, 'doctor', $clinic->id);
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $case = CaseRecord::factory()->open()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'vertical_id' => $clinic->vertical_id,
        'opened_at' => now(),
    ]);

    return compact('clinic', 'owner', 'doctorUser', 'doctor', 'patient', 'case');
}

// ---------------------------------------------------------------------------
// Notes — always editable (deletion-retention rule)
// ---------------------------------------------------------------------------

it('updates case notes and redirects to the case show page', function (): void {
    ['owner' => $owner, 'case' => $case] = cntSetup();

    $this->actingAs($owner)
        ->patch(route('cases.notes.update', $case), ['notes' => 'Updated case notes.'])
        ->assertRedirect(route('cases.show', $case));

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->notes)->toBe('Updated case notes.');
});

it('clears case notes when null is sent', function (): void {
    ['owner' => $owner, 'case' => $case] = cntSetup();
    $case->updateQuietly(['notes' => 'Some old notes']);

    $this->actingAs($owner)
        ->patch(route('cases.notes.update', $case), ['notes' => null]);

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->notes)->toBeNull();
});

it('notes update succeeds even for a Closed case', function (): void {
    ['owner' => $owner, 'case' => $case] = cntSetup();
    $case->updateQuietly(['status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($owner)
        ->patch(route('cases.notes.update', $case), ['notes' => 'Post-close note'])
        ->assertRedirect(route('cases.show', $case));

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->notes)->toBe('Post-close note');
});

// ---------------------------------------------------------------------------
// Title edit window (deletion-retention rule: 48h from opened_at)
// ---------------------------------------------------------------------------

it('updates the title when within the edit window', function (): void {
    ['owner' => $owner, 'case' => $case] = cntSetup();
    // opened_at = now() is within the 48h window.

    $this->actingAs($owner)
        ->patch(route('cases.title.update', $case), ['title' => 'Revised Title'])
        ->assertRedirect(route('cases.show', $case));

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->title)->toBe('Revised Title');
});

it('rejects the title update when the edit window has expired', function (): void {
    ['owner' => $owner, 'case' => $case] = cntSetup();

    // Move opened_at to 3 days ago (well outside the 48h window).
    $case->updateQuietly(['opened_at' => Carbon::now()->subDays(3)]);

    $this->actingAs($owner)
        ->patch(route('cases.title.update', $case), ['title' => 'Too Late'])
        ->assertSessionHasErrors('title');

    $fresh = CaseRecord::withoutGlobalScopes()->find($case->id);
    expect($fresh->title)->not->toBe('Too Late');
});

it('rejects an empty title', function (): void {
    ['owner' => $owner, 'case' => $case] = cntSetup();

    $this->actingAs($owner)
        ->patch(route('cases.title.update', $case), ['title' => ''])
        ->assertSessionHasErrors('title');
});
