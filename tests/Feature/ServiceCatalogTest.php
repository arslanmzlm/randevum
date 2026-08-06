<?php

use App\Models\Clinic;
use App\Models\Service;
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
function scTestRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a valid POST /services payload.
 *
 * @return array<string, mixed>
 */
function scStorePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Ayak Bakımı',
        'description' => 'Tam ayak bakım hizmeti.',
        'price' => '350.00',
        'default_complaint' => null,
        'default_diagnosis' => null,
        'default_treatment_process' => null,
        'is_active' => true,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /services — access control + rendering
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /services', function (): void {
    $this->get(route('services.index'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /services and the Index component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('services/Index')
            ->has('services')
            ->has('auth.permissions')
        );
});

it('index canManage is true for owner and false for doctor role', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $doctorUser = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);
    scTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($owner)
        ->get(route('services.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('services.create')));

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($doctorUser)
        ->get(route('services.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('services.create')));
});

it('manager can access GET /services and canManage is true', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    scTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('services.create')));
});

it('index lists only the active clinic\'s services', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinicA->id);

    Service::factory()->create(['clinic_id' => $clinicA->id, 'vertical_id' => $clinicA->vertical_id, 'name' => 'Service Alpha']);
    Service::factory()->create(['clinic_id' => $clinicB->id, 'vertical_id' => $clinicB->vertical_id, 'name' => 'Service Beta']);

    $this->actingAs($owner)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.name', 'Service Alpha')
        );
});

it('doctor role can access GET /services (read-only)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    scTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('services/Index'));
});

// ---------------------------------------------------------------------------
// GET /services/create — access control + rendering
// ---------------------------------------------------------------------------

it('owner is redirected from GET /services/create to the list with the create dialog open', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('services.create'))
        ->assertRedirect(route('services.index', ['new' => 1]));
});

it('manager can access GET /services/create', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    scTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('services.create'))
        ->assertRedirect(route('services.index', ['new' => 1]));
});

it('doctor role gets 403 on GET /services/create', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    scTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('services.create'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// POST /services — store
// ---------------------------------------------------------------------------

it('owner can create a service and is redirected to the index', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload())
        ->assertRedirect(route('services.index'));
});

it('store auto-sets clinic_id from ClinicContext', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['name' => 'Clinic Check']));

    $service = Service::withoutGlobalScopes()
        ->where('name', 'Clinic Check')
        ->first();

    expect($service)->not->toBeNull()
        ->and($service->clinic_id)->toBe($clinic->id);
});

it('store auto-sets vertical_id from the active clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['name' => 'Vertical Check']));

    $service = Service::withoutGlobalScopes()
        ->where('name', 'Vertical Check')
        ->first();

    expect($service)->not->toBeNull()
        ->and($service->vertical_id)->toBe($clinic->vertical_id);
});

it('price is persisted as a decimal value', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['name' => 'Decimal Test', 'price' => '1250.75']));

    $service = Service::withoutGlobalScopes()
        ->where('name', 'Decimal Test')
        ->first();

    expect($service)->not->toBeNull()
        ->and((float) $service->price)->toBe(1250.75);
});

it('store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload())
        ->assertSessionHas('toasts');
});

it('doctor role gets 403 on POST /services', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    scTestRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('services.store'), scStorePayload())
        ->assertForbidden();
});

it('manager can create a service', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    scTestRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('services.store'), scStorePayload(['name' => 'Manager Service']))
        ->assertRedirect(route('services.index'));

    expect(Service::withoutGlobalScopes()->where('name', 'Manager Service')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Validation — store
// ---------------------------------------------------------------------------

it('store rejects a missing name with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

it('store rejects a negative price with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['price' => '-10']))
        ->assertSessionHasErrors('price');
});

it('store rejects a missing price with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['price' => null]))
        ->assertSessionHasErrors('price');
});

it('store rejects a name longer than 255 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['name' => str_repeat('a', 256)]))
        ->assertSessionHasErrors('name');
});

it('store rejects a description longer than 2000 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['description' => str_repeat('x', 2001)]))
        ->assertSessionHasErrors('description');
});

it('store persists duration_minutes', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['name' => 'Timed', 'duration_minutes' => 45]));

    $service = Service::withoutGlobalScopes()->where('name', 'Timed')->first();

    expect($service)->not->toBeNull()
        ->and($service->duration_minutes)->toBe(45);
});

it('store rejects a duration_minutes below the 5-minute minimum', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['duration_minutes' => 3]))
        ->assertSessionHasErrors('duration_minutes');
});

it('store allows an empty duration_minutes (falls back to clinic default)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['name' => 'No Duration', 'duration_minutes' => null]))
        ->assertSessionHasNoErrors();

    expect(Service::withoutGlobalScopes()->where('name', 'No Duration')->first()->duration_minutes)
        ->toBeNull();
});

it('update persists duration_minutes', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'duration_minutes' => null,
    ]);

    $this->actingAs($owner)
        ->put(route('services.update', $service), scStorePayload(['duration_minutes' => 60]))
        ->assertRedirect(route('services.index'));

    expect($service->fresh()->duration_minutes)->toBe(60);
});

it('store rejects a default_complaint longer than 5000 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('services.store'), scStorePayload(['default_complaint' => str_repeat('c', 5001)]))
        ->assertSessionHasErrors('default_complaint');
});

// ---------------------------------------------------------------------------
// GET /services/{service}/edit — access control + rendering
// ---------------------------------------------------------------------------

it('owner is redirected from the edit route to the list with that row open', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('services.edit', $service))
        ->assertRedirect(route('services.index', ['edit' => $service->id]));
});

it('manager can access the edit page', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    scTestRole($manager, 'manager', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($manager)
        ->get(route('services.edit', $service))
        ->assertRedirect(route('services.index', ['edit' => $service->id]));
});

it('doctor role gets 403 on GET /services/{service}/edit', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    scTestRole($doctorUser, 'doctor', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($doctorUser)
        ->get(route('services.edit', $service))
        ->assertForbidden();
});

it('the list resolves ?edit into an editing prop with the resource shape', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Tırnak Bakımı',
        'price' => '450.00',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('services.index', ['edit' => $service->id]))
        ->assertInertia(fn ($page) => $page
            ->where('editing.id', $service->id)
            ->where('editing.name', 'Tırnak Bakımı')
            ->where('editing.is_active', true)
        );
});

it('the list leaves editing null without ?edit and for a viewer without update rights', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    scTestRole($doctorUser, 'doctor', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('services.index'))
        ->assertInertia(fn ($page) => $page->where('editing', null));

    $this->actingAs($doctorUser)
        ->get(route('services.index', ['edit' => $service->id]))
        ->assertInertia(fn ($page) => $page->where('editing', null));
});

// ---------------------------------------------------------------------------
// PUT /services/{service} — update
// ---------------------------------------------------------------------------

it('owner can update a service', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Old Name',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->put(route('services.update', $service), scStorePayload([
            'name' => 'New Name',
            'is_active' => false,
        ]))
        ->assertRedirect(route('services.index'));

    $fresh = $service->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->is_active)->toBeFalse();
});

it('manager can update a service', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    scTestRole($manager, 'manager', $clinic->id);

    $service = Service::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Manager Old Name',
    ]);

    $this->actingAs($manager)
        ->put(route('services.update', $service), scStorePayload(['name' => 'Manager New Name']))
        ->assertRedirect();

    expect($service->fresh()->name)->toBe('Manager New Name');
});

it('update flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->put(route('services.update', $service), scStorePayload())
        ->assertSessionHas('toasts');
});

it('doctor role gets 403 on PUT /services/{service}', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    scTestRole($doctorUser, 'doctor', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($doctorUser)
        ->put(route('services.update', $service), scStorePayload())
        ->assertForbidden();
});

it('update rejects a missing name with a validation error', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->put(route('services.update', $service), scStorePayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

// ---------------------------------------------------------------------------
// DELETE /services/{service} — soft delete
// ---------------------------------------------------------------------------

it('owner can soft-delete a service via DELETE /services/{service}', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->delete(route('services.destroy', $service))
        ->assertRedirect(route('services.index'));

    expect(Service::withoutGlobalScopes()->find($service->id)->deleted_at)->not->toBeNull();
});

it('soft delete leaves the row in the database', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->delete(route('services.destroy', $service));

    expect(Service::withoutGlobalScopes()->find($service->id))->not->toBeNull();
});

it('manager can soft-delete a service', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    scTestRole($manager, 'manager', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($manager)
        ->delete(route('services.destroy', $service))
        ->assertRedirect();

    expect(Service::withoutGlobalScopes()->find($service->id)->deleted_at)->not->toBeNull();
});

it('doctor role gets 403 on DELETE /services/{service}', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    scTestRole($doctorUser, 'doctor', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($doctorUser)
        ->delete(route('services.destroy', $service))
        ->assertForbidden();
});

it('DELETE /services/{service} redirects with a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $service = Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->delete(route('services.destroy', $service))
        ->assertRedirect(route('services.index'))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// GET /services — server-side paginator shape, search, sort, is_active filter
// ---------------------------------------------------------------------------

it('index returns a paginated shape with data and meta', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    Service::factory()->count(3)->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('services.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('services.data', 3)
            ->has('services.meta')
            ->where('services.meta.total', 3)
        );
});

it('index echoes the filters prop', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('services.index', ['filter' => ['search' => 'test'], 'per_page' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('query.filter.search', 'test')
            ->where('query.per_page', 10)
        );
});

it('index filters services by search on name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Ayak Bakımı']);
    Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'El Bakımı']);

    $this->actingAs($owner)
        ->get(route('services.index', ['filter' => ['search' => 'Ayak']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('services.data', 1)
            ->where('services.data.0.name', 'Ayak Bakımı')
        );
});

it('index filters services by is_active = true', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'is_active' => true]);
    Service::factory()->inactive()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('services.index', ['filter' => ['is_active' => '1']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('services.data', 1));
});

it('index filters services by is_active = false', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'is_active' => true]);
    Service::factory()->inactive()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $this->actingAs($owner)
        ->get(route('services.index', ['filter' => ['is_active' => '0']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('services.data', 1));
});

it('index sorts services by price ascending when sort_order is 1', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Expensive', 'price' => '500.00']);
    Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Cheap', 'price' => '100.00']);

    $this->actingAs($owner)
        ->get(route('services.index', ['sort' => 'price']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('services.data.0.name', 'Cheap')
            ->where('services.data.1.name', 'Expensive')
        );
});

// ---------------------------------------------------------------------------
// inactive() factory state
// ---------------------------------------------------------------------------

it('inactive factory state sets is_active to false', function (): void {
    $service = Service::factory()->inactive()->make();

    expect($service->is_active)->toBeFalse();
});

it('inactive service is excluded from the active scope', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    scTestRole($owner, 'owner', $clinic->id);

    Service::factory()->inactive()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);
    Service::factory()->create(['clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id]);

    $activeCount = Service::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->active()
        ->count();

    expect($activeCount)->toBe(1);
});
