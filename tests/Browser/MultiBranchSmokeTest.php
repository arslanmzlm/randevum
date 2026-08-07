<?php

use App\Models\Clinic;
use App\Models\User;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/**
 * Browser smoke — Feature 1.73 (Çoklu şube). Real Chromium via pest-plugin-browser:
 * the branch list page and the reports "branch" tab must mount, render page-body
 * content (not just the shell), and have no JavaScript errors, for an owner who
 * belongs to two clinics of one tenant (multi-branch topbar switcher visible).
 *
 * Hardened against Vue setup-error false greens: assertNoJavascriptErrors() only
 * catches *uncaught* window errors. The assertSee() calls on in-body labels plus
 * the non-empty <main> script assert provide the real guard.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function mbsRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('renders the branch list page with the topbar switcher and no JS errors', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Podosen Ana Şube']);
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'name' => 'Podosen Kadıköy Şube']);

    $owner = User::factory()->create();
    mbsRole($owner, 'owner', $clinicA->id);
    mbsRole($owner, 'owner', $clinicB->id);

    $this->actingAs($owner);

    visit('/settings/branches')
        ->assertNoJavascriptErrors()
        ->assertSee('Podosen Ana Şube')
        ->assertSee('Podosen Kadıköy Şube')
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});

it('renders the reports branch tab with per-branch rows and no JS errors', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Podosen Ana Şube']);
    $clinicB = Clinic::factory()->create(['tenant_id' => $clinicA->tenant_id, 'name' => 'Podosen Kadıköy Şube']);

    $owner = User::factory()->create();
    mbsRole($owner, 'owner', $clinicA->id);
    mbsRole($owner, 'owner', $clinicB->id);

    $this->actingAs($owner);

    visit('/reports?tab=branch')
        ->assertNoJavascriptErrors()
        ->assertSee('Podosen Ana Şube')
        ->assertSee('Podosen Kadıköy Şube')
        ->assertScript(
            '() => (document.querySelector("main")?.innerText.trim().length ?? 0) > 0',
        )
        ->screenshot();
});
