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

function spRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('shares the active-clinic permission names for the authenticated user (useCan source)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    spRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => $p->contains('clinic.update')
                && $p->contains('patients.create'),
        ));
});

it('omits permissions the user does not hold', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    spRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => ! $p->contains('clinic.update')
                && $p->contains('patients.viewAny'),
        ));
});

it('gives a doctor doctors.viewAny (drives the sidebar Doktorlar entry) without doctors.create', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    spRole($doctorUser, 'doctor', $clinic->id);

    // AppLayout.vue gates the "Doktorlar" nav entry on doctors.viewAny (matching
    // DoctorController::index's authorize call) — not doctors.create, which only owner/manager
    // hold. A doctor who can open the page server-side must also see the nav entry to it.
    $this->actingAs($doctorUser)
        ->get(route('dashboard'))
        ->assertInertia(fn ($page) => $page->where(
            'auth.permissions',
            fn ($p) => $p->contains('doctors.viewAny')
                && ! $p->contains('doctors.create'),
        ));
});
