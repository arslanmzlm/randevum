<?php

use App\Models\Clinic;
use App\Models\Patient;
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

function csiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('switching to a clinic of another tenant → 403 and the session stays on the original clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create(); // different tenant (factory default)
    $ownerA = User::factory()->create();
    csiRole($ownerA, 'owner', $clinicA->id);

    $this->actingAs($ownerA)
        ->post(route('clinics.switch'), ['clinic_id' => $clinicB->id])
        ->assertForbidden();

    // Session untouched — the active clinic is still A on the next request.
    $this->actingAs($ownerA)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clinic.id', $clinicA->id));
});

it('switching to a same-tenant clinic the user holds no role in → 403', function (): void {
    $tenantOwnerClinic = Clinic::factory()->create();
    $siblingBranch = Clinic::factory()->create(['tenant_id' => $tenantOwnerClinic->tenant_id]);
    $owner = User::factory()->create();
    csiRole($owner, 'owner', $tenantOwnerClinic->id);

    $this->actingAs($owner)
        ->post(route('clinics.switch'), ['clinic_id' => $siblingBranch->id])
        ->assertForbidden();
});

it("after a legitimate A→B switch, GET /patients shows only B's patients and none of A's", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    csiRole($owner, 'owner', $clinicA->id);
    csiRole($owner, 'owner', $clinicB->id);

    Patient::factory()->create(['clinic_id' => $clinicA->id, 'first_name' => 'Alfa', 'last_name' => 'Hasta']);
    Patient::factory()->create(['clinic_id' => $clinicB->id, 'first_name' => 'Beta', 'last_name' => 'Hasta']);

    $this->actingAs($owner)
        ->post(route('clinics.switch'), ['clinic_id' => $clinicB->id])
        ->assertRedirect(route('dashboard'));

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('patients.data', 1)
            ->where('patients.data.0.first_name', 'Beta')
        );
});
