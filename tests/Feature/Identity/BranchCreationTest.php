<?php

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\FollowUpType;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use App\Models\Vertical;
use App\Scopes\ClinicScope;
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
    $this->podiatryVertical = Vertical::where('slug', 'podiatry')->firstOrFail();
});

function branchCreationTestAssignRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

it('owner creates a branch: new clinic under the tenant, creator holds owner, appointment/follow-up types provisioned', function (): void {
    $source = Clinic::factory()->create(['vertical_id' => $this->podiatryVertical->id]);
    $owner = User::factory()->create();
    branchCreationTestAssignRole($owner, 'owner', $source->id);

    $this->actingAs($owner)
        ->post(route('settings.branches.store'), [
            'name' => 'İkinci Şube',
            'vertical_id' => $this->podiatryVertical->id,
            'copy_catalog' => false,
        ])
        ->assertRedirect(route('settings.branches.index'));

    $branch = Clinic::where('name', 'İkinci Şube')->firstOrFail();

    expect($branch->tenant_id)->toBe($source->tenant_id);

    app(PermissionRegistrar::class)->setPermissionsTeamId($branch->id);
    $owner->unsetRelation('roles');
    expect($owner->hasRole('owner'))->toBeTrue();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $owner->unsetRelation('roles');

    $apptTypeCount = AppointmentType::withoutGlobalScopes()->where('clinic_id', $branch->id)->count();
    $followUpCount = FollowUpType::withoutGlobalScopes()->where('clinic_id', $branch->id)->count();

    expect($apptTypeCount)->toBe(count(config('podiatry.appointment_types', [])))
        ->and($followUpCount)->toBe(count(config('platform.follow_ups.default_types', [])));
});

it('copy_catalog true copies active services/products with clinic_id = new branch and current_stock = 0; false copies nothing', function (): void {
    $source = Clinic::factory()->create(['vertical_id' => $this->podiatryVertical->id]);
    $owner = User::factory()->create();
    branchCreationTestAssignRole($owner, 'owner', $source->id);

    $activeService = Service::factory()->create([
        'clinic_id' => $source->id, 'vertical_id' => $source->vertical_id, 'name' => 'Aktif Hizmet', 'is_active' => true,
    ]);
    $inactiveService = Service::factory()->create([
        'clinic_id' => $source->id, 'vertical_id' => $source->vertical_id, 'name' => 'Pasif Hizmet', 'is_active' => false,
    ]);
    $activeProduct = Product::factory()->create([
        'clinic_id' => $source->id, 'vertical_id' => $source->vertical_id, 'name' => 'Aktif Ürün',
        'is_active' => true, 'current_stock' => 42,
    ]);
    $deletedProduct = Product::factory()->create([
        'clinic_id' => $source->id, 'vertical_id' => $source->vertical_id, 'name' => 'Silinmiş Ürün', 'is_active' => true,
    ]);
    $deletedProduct->delete();

    $this->actingAs($owner)
        ->post(route('settings.branches.store'), [
            'name' => 'Kopyalı Şube',
            'vertical_id' => $this->podiatryVertical->id,
            'copy_catalog' => true,
        ])
        ->assertRedirect(route('settings.branches.index'));

    $branch = Clinic::where('name', 'Kopyalı Şube')->firstOrFail();

    $copiedServices = Service::withoutGlobalScope(ClinicScope::class)->where('clinic_id', $branch->id)->pluck('name');
    $copiedProducts = Product::withoutGlobalScope(ClinicScope::class)->where('clinic_id', $branch->id)->get();

    expect($copiedServices)->toContain('Aktif Hizmet')
        ->not->toContain('Pasif Hizmet')
        ->and($copiedProducts->pluck('name'))->toContain('Aktif Ürün')
        ->not->toContain('Silinmiş Ürün')
        ->and($copiedProducts->firstWhere('name', 'Aktif Ürün')->current_stock)->toBe(0);

    // Second branch, copy_catalog=false — nothing copied.
    $this->actingAs($owner)
        ->post(route('settings.branches.store'), [
            'name' => 'Kopyasız Şube',
            'vertical_id' => $this->podiatryVertical->id,
            'copy_catalog' => false,
        ])
        ->assertRedirect(route('settings.branches.index'));

    $emptyBranch = Clinic::where('name', 'Kopyasız Şube')->firstOrFail();

    expect(Service::withoutGlobalScope(ClinicScope::class)->where('clinic_id', $emptyBranch->id)->count())->toBe(0)
        ->and(Product::withoutGlobalScope(ClinicScope::class)->where('clinic_id', $emptyBranch->id)->count())->toBe(0);
});

it('copy_catalog with a different vertical_id fails validation', function (): void {
    $source = Clinic::factory()->create(['vertical_id' => $this->podiatryVertical->id]);
    $otherVertical = Vertical::factory()->create(['is_active' => true]);
    $owner = User::factory()->create();
    branchCreationTestAssignRole($owner, 'owner', $source->id);

    $this->actingAs($owner)
        ->post(route('settings.branches.store'), [
            'name' => 'Uyumsuz Şube',
            'vertical_id' => $otherVertical->id,
            'copy_catalog' => true,
        ])
        ->assertInvalid(['copy_catalog']);

    expect(Clinic::where('name', 'Uyumsuz Şube')->exists())->toBeFalse();
});

it('manager (no clinics.create) gets 403 on index/create/store', function (): void {
    $clinic = Clinic::factory()->create(['vertical_id' => $this->podiatryVertical->id]);
    $manager = User::factory()->create();
    branchCreationTestAssignRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)->get(route('settings.branches.index'))->assertForbidden();
    $this->actingAs($manager)->get(route('settings.branches.create'))->assertForbidden();
    $this->actingAs($manager)
        ->post(route('settings.branches.store'), [
            'name' => 'Yasak Şube',
            'vertical_id' => $this->podiatryVertical->id,
            'copy_catalog' => false,
        ])
        ->assertForbidden();
});
