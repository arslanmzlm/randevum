<?php

use App\Models\Clinic;
use App\Models\User;
use App\Models\Vertical;
use App\Support\ClinicContext;
use Database\Seeders\CountrySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class, CountrySeeder::class, VerticalSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

function btiRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it("tenant A's owner never sees tenant B's clinics in settings.branches.index", function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Tenant A Ana']);
    $siblingA = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'name' => 'Tenant A Şube']);
    $clinicB = Clinic::factory()->create(['name' => 'Tenant B Ana']);

    $ownerA = User::factory()->create();
    btiRole($ownerA, 'owner', $clinicA->id);

    $this->actingAs($ownerA)
        ->get(route('settings.branches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('branches', function ($branches) use ($clinicA, $siblingA, $clinicB) {
            $names = collect($branches)->pluck('name');

            return $names->contains($clinicA->name)
                && $names->contains($siblingA->name)
                && ! $names->contains($clinicB->name);
        }));
});

it('a created branch is never attached to another tenant — the endpoint takes no tenant_id input', function (): void {
    $source = Clinic::factory()->create();
    $vertical = Vertical::where('slug', 'podiatry')->firstOrFail();
    $owner = User::factory()->create();
    btiRole($owner, 'owner', $source->id);

    // No tenant_id field exists on the request at all — tampering has nothing to target.
    $this->actingAs($owner)
        ->post(route('settings.branches.store'), [
            'name' => 'Yeni Şube',
            'vertical_id' => $vertical->id,
            'copy_catalog' => false,
            'tenant_id' => 999999,
        ])
        ->assertRedirect(route('settings.branches.index'));

    $branch = Clinic::where('name', 'Yeni Şube')->firstOrFail();

    expect($branch->tenant_id)->toBe($source->tenant_id)
        ->not->toBe(999999);
});
