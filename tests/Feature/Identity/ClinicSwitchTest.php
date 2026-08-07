<?php

use App\Models\Clinic;
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

function clinicSwitchTestAssignRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('switching puts active_clinic_id in the session and the next request resolves that clinic', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Clinic A']);
    $clinicB = Clinic::factory()->create(['name' => 'Clinic B']);
    $owner = User::factory()->create();
    clinicSwitchTestAssignRole($owner, 'owner', $clinicA->id);
    clinicSwitchTestAssignRole($owner, 'owner', $clinicB->id);

    $this->actingAs($owner)
        ->post(route('clinics.switch'), ['clinic_id' => $clinicB->id])
        ->assertRedirect(route('dashboard'));

    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clinic.id', $clinicB->id));
});

it('a user with one membership gets availableClinics === [] (switcher hidden)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicSwitchTestAssignRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('availableClinics', []));
});

it('a stale/invalid session id is ignored, forgotten, and falls back to the first membership', function (): void {
    $clinicA = Clinic::factory()->create();
    $foreignClinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    clinicSwitchTestAssignRole($owner, 'owner', $clinicA->id);

    // Simulate a session carrying a clinic id the user holds no role in.
    $this->withSession(['active_clinic_id' => $foreignClinic->id])
        ->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clinic.id', $clinicA->id));

    // The stale id must have been forgotten — a subsequent request (fresh session
    // read) still resolves to the user's real membership, not a resurrected value.
    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clinic.id', $clinicA->id));
});

it("after switching, auth.permissions reflects the new clinic's role set (relations unset + cache forgotten)", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $user = User::factory()->create();
    clinicSwitchTestAssignRole($user, 'manager', $clinicA->id);
    clinicSwitchTestAssignRole($user, 'receptionist', $clinicB->id);

    $this->actingAs($user)
        ->post(route('clinics.switch'), ['clinic_id' => $clinicB->id])
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // manager-only ability must be gone, receptionist ability present.
            ->where('auth.permissions', fn ($p) => ! $p->contains('clinics.create')
                && $p->contains('appointments.viewAny'))
        );
});

it('availableClinics is filtered by the switchTo predicate, not the raw membership set', function (): void {
    // owner@A (switchable), receptionist@B (not switchable), receptionist@C (not switchable).
    $clinicA = Clinic::factory()->create(['name' => 'Clinic A']);
    $clinicB = Clinic::factory()->create(['name' => 'Clinic B']);
    $clinicC = Clinic::factory()->create(['name' => 'Clinic C']);
    $user = User::factory()->create();
    clinicSwitchTestAssignRole($user, 'owner', $clinicA->id);
    clinicSwitchTestAssignRole($user, 'receptionist', $clinicB->id);
    clinicSwitchTestAssignRole($user, 'receptionist', $clinicC->id);

    // From A (switchable), switching to C is allowed — A's "or active clinic" leg.
    $this->actingAs($user)
        ->post(route('clinics.switch'), ['clinic_id' => $clinicC->id])
        ->assertRedirect(route('dashboard'));

    // Active is now C (not switchable), switchable = [A]. B must not be listed —
    // it would 403 on click since neither B nor the active clinic C is switchable.
    $this->actingAs($user)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('availableClinics', fn ($clinics) => collect($clinics)->pluck('id')->all() === [$clinicA->id]
        ));

    // And the server agrees: switching to B (not offered) is indeed forbidden.
    $this->actingAs($user)
        ->post(route('clinics.switch'), ['clinic_id' => $clinicB->id])
        ->assertForbidden();
});

it('a manager@A / receptionist@B user can switch back from B to A (no lock-in)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $user = User::factory()->create();
    clinicSwitchTestAssignRole($user, 'manager', $clinicA->id);
    clinicSwitchTestAssignRole($user, 'receptionist', $clinicB->id);

    // Active clinic defaults to the lowest membership id (clinicA), which is
    // switchable (manager holds clinics.switch) — switching into B is allowed.
    $this->actingAs($user)
        ->post(route('clinics.switch'), ['clinic_id' => $clinicB->id])
        ->assertRedirect(route('dashboard'));

    // Now active = B (receptionist, NOT switchable) — the "or active clinic"
    // policy leg must still allow the return trip to A (switchable).
    $this->actingAs($user)
        ->post(route('clinics.switch'), ['clinic_id' => $clinicA->id])
        ->assertRedirect(route('dashboard'));

    $this->actingAs($user)
        ->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('activeClinic.id', $clinicA->id));
});
