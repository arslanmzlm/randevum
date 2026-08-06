<?php

use App\Models\AppointmentType;
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

/**
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function atRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a valid POST /appointment-types payload.
 *
 * @return array<string, mixed>
 */
function atStorePayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Muayene',
        'color' => '#0D9488',
        'default_duration_minutes' => 30,
        'is_active' => true,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /appointment-types — access control + rendering
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /appointment-types', function (): void {
    $this->get(route('appointment-types.index'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /appointment-types and the Index component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('appointment-types/Index')
            ->has('appointmentTypes')
            ->has('auth.permissions')
        );
});

it('index canManage is true for owner', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointment-types.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('appointmentTypes.create')));
});

it('index canManage is false for doctor role', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    atRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('appointmentTypes.create')));
});

it('manager can access GET /appointment-types and canManage is true', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    atRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('appointmentTypes.create')));
});

it('doctor can access GET /appointment-types (read-only)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    atRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('appointment-types/Index'));
});

it('index lists only the active clinic\'s appointment types', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinicA->id);

    AppointmentType::factory()->create([
        'clinic_id' => $clinicA->id,
        'vertical_id' => $clinicA->vertical_id,
        'name' => 'Muayene A',
    ]);
    AppointmentType::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
        'name' => 'Muayene B',
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('appointmentTypes.data', 1)
            ->where('appointmentTypes.data.0.name', 'Muayene A')
        );
});

it('index returns a paginated shape with data and meta', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    AppointmentType::factory()->count(3)->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('appointmentTypes.data', 3)
            ->has('appointmentTypes.meta')
            ->where('appointmentTypes.meta.total', 3)
        );
});

it('index resource shape exposes expected fields', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Kontrol',
        'color' => '#0891B2',
        'default_duration_minutes' => 20,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.index'))
        ->assertInertia(fn ($page) => $page
            ->has('appointmentTypes.data.0.id')
            ->where('appointmentTypes.data.0.name', 'Kontrol')
            ->where('appointmentTypes.data.0.color', '#0891B2')
            ->where('appointmentTypes.data.0.default_duration_minutes', 20)
            ->where('appointmentTypes.data.0.is_active', true)
        );
});

it('index echoes the query filter state', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointment-types.index', ['filter' => ['search' => 'test'], 'per_page' => 10]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('query.filter.search', 'test')
            ->where('query.per_page', 10)
        );
});

it('index filters appointment types by search on name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Muayene',
    ]);
    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'name' => 'Seans',
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.index', ['filter' => ['search' => 'Muayene']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('appointmentTypes.data', 1)
            ->where('appointmentTypes.data.0.name', 'Muayene')
        );
});

it('index filters appointment types by is_active = true', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'is_active' => true,
    ]);
    AppointmentType::factory()->inactive()->create([
        'clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.index', ['filter' => ['is_active' => '1']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointmentTypes.data', 1));
});

it('index filters appointment types by is_active = false', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id, 'is_active' => true,
    ]);
    AppointmentType::factory()->inactive()->create([
        'clinic_id' => $clinic->id, 'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.index', ['filter' => ['is_active' => '0']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('appointmentTypes.data', 1));
});

// ---------------------------------------------------------------------------
// GET /appointment-types/create — access control + redirect to the list dialog
// ---------------------------------------------------------------------------

it('owner is redirected from GET /appointment-types/create to the list with the create dialog open', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointment-types.create'))
        ->assertRedirect(route('appointment-types.index', ['new' => 1]));
});

it('manager can access GET /appointment-types/create', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    atRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('appointment-types.create'))
        ->assertRedirect(route('appointment-types.index', ['new' => 1]));
});

it('doctor role gets 403 on GET /appointment-types/create', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    atRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->get(route('appointment-types.create'))
        ->assertForbidden();
});

it('assistant role gets 403 on GET /appointment-types/create', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    atRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('appointment-types.create'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// POST /appointment-types — store
// ---------------------------------------------------------------------------

it('owner can create an appointment type and is redirected to index', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload())
        ->assertRedirect(route('appointment-types.index'));
});

it('store auto-sets clinic_id from ClinicContext', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['name' => 'Clinic Check']));

    $type = AppointmentType::withoutGlobalScopes()
        ->where('name', 'Clinic Check')
        ->first();

    expect($type)->not->toBeNull()
        ->and($type->clinic_id)->toBe($clinic->id);
});

it('store auto-derives vertical_id from the active clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['name' => 'Vertical Check']));

    $type = AppointmentType::withoutGlobalScopes()
        ->where('name', 'Vertical Check')
        ->first();

    expect($type)->not->toBeNull()
        ->and($type->vertical_id)->toBe($clinic->vertical_id);
});

it('store persists color in uppercase #RRGGBB format when lowercase input is sent', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload([
            'name' => 'Color Check',
            'color' => '#0d9488',
        ]));

    $type = AppointmentType::withoutGlobalScopes()->where('name', 'Color Check')->first();

    expect($type)->not->toBeNull()
        ->and($type->color)->toBe('#0D9488');
});

it('store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload())
        ->assertSessionHas('toasts');
});

it('manager can create an appointment type', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    atRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->post(route('appointment-types.store'), atStorePayload(['name' => 'Manager Type']))
        ->assertRedirect(route('appointment-types.index'));

    expect(AppointmentType::withoutGlobalScopes()->where('name', 'Manager Type')->exists())->toBeTrue();
});

it('doctor role gets 403 on POST /appointment-types', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    atRole($doctorUser, 'doctor', $clinic->id);

    $this->actingAs($doctorUser)
        ->post(route('appointment-types.store'), atStorePayload())
        ->assertForbidden();
});

it('assistant role gets 403 on POST /appointment-types', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    atRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('appointment-types.store'), atStorePayload())
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// Validation — store
// ---------------------------------------------------------------------------

it('store rejects a missing name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

it('store rejects a name longer than 100 characters', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['name' => str_repeat('a', 101)]))
        ->assertSessionHasErrors('name');
});

it('store rejects a missing color', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['color' => null]))
        ->assertSessionHasErrors('color');
});

it('store rejects an invalid color format (not #RRGGBB)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['color' => 'red']))
        ->assertSessionHasErrors('color');
});

it('store rejects a color with too few hex digits', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['color' => '#FFF']))
        ->assertSessionHasErrors('color');
});

it('store rejects a missing default_duration_minutes', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['default_duration_minutes' => null]))
        ->assertSessionHasErrors('default_duration_minutes');
});

it('store rejects default_duration_minutes below minimum (4)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['default_duration_minutes' => 4]))
        ->assertSessionHasErrors('default_duration_minutes');
});

it('store rejects default_duration_minutes above maximum (481)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['default_duration_minutes' => 481]))
        ->assertSessionHasErrors('default_duration_minutes');
});

it('store accepts boundary value default_duration_minutes = 5', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['name' => 'Min Duration', 'default_duration_minutes' => 5]))
        ->assertSessionHasNoErrors();
});

it('store accepts boundary value default_duration_minutes = 480', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('appointment-types.store'), atStorePayload(['name' => 'Max Duration', 'default_duration_minutes' => 480]))
        ->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------------------
// GET /appointment-types/{appointmentType}/edit — access control + rendering
// ---------------------------------------------------------------------------

it('owner is redirected from the edit route to the list with that row open', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.edit', $type))
        ->assertRedirect(route('appointment-types.index', ['edit' => $type->id]));
});

it('manager can access the edit page', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    atRole($manager, 'manager', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($manager)
        ->get(route('appointment-types.edit', $type))
        ->assertRedirect(route('appointment-types.index', ['edit' => $type->id]));
});

it('doctor role gets 403 on GET /appointment-types/{appointmentType}/edit', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    atRole($doctorUser, 'doctor', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($doctorUser)
        ->get(route('appointment-types.edit', $type))
        ->assertForbidden();
});

it('the list resolves ?edit into an editing prop with the resource shape', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Seans',
        'color' => '#7C3AED',
        'default_duration_minutes' => 45,
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.index', ['edit' => $type->id]))
        ->assertInertia(fn ($page) => $page
            ->where('editing.id', $type->id)
            ->where('editing.name', 'Seans')
            ->where('editing.color', '#7C3AED')
            ->where('editing.default_duration_minutes', 45)
            ->where('editing.is_active', true)
        );
});

it('the list leaves the editing prop null without ?edit', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('appointment-types.index'))
        ->assertInertia(fn ($page) => $page->where('editing', null));
});

it('the list leaves editing null for a viewer without update rights', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    atRole($doctorUser, 'doctor', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($doctorUser)
        ->get(route('appointment-types.index', ['edit' => $type->id]))
        ->assertInertia(fn ($page) => $page->where('editing', null));
});

it('the list ignores an ?edit id belonging to another clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinicA->id);

    $typeB = AppointmentType::factory()->create([
        'clinic_id' => $clinicB->id,
        'vertical_id' => $clinicB->vertical_id,
    ]);

    $this->actingAs($owner)
        ->get(route('appointment-types.index', ['edit' => $typeB->id]))
        ->assertInertia(fn ($page) => $page->where('editing', null));
});

it('returns the created type as json for a non-Inertia request, so a quick-add can select it', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->postJson(route('appointment-types.store'), [
            'name' => 'Hızlı Kontrol',
            'color' => '#0D9488',
            'default_duration_minutes' => 25,
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Hızlı Kontrol')
        ->assertJsonPath('data.default_duration_minutes', 25)
        ->assertJsonStructure(['data' => ['id', 'name', 'color', 'default_duration_minutes', 'is_active']]);
});

it('returns to the list the save came from, keeping its filters and dropping the dialog params', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $from = route('appointment-types.index').'?filter[is_active]=0&page=2&new=1';

    $this->actingAs($owner)
        ->from($from)
        ->post(route('appointment-types.store'), [
            'name' => 'Kontrol',
            'color' => '#0D9488',
            'default_duration_minutes' => 20,
            'is_active' => true,
        ])
        ->assertRedirect(route('appointment-types.index').'?'.http_build_query([
            'filter' => ['is_active' => '0'],
            'page' => '2',
        ]));
});

it('keeps the redirect for an Inertia store, so the list still gets its toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
        ->post(route('appointment-types.store'), [
            'name' => 'Kontrol',
            'color' => '#0D9488',
            'default_duration_minutes' => 20,
            'is_active' => true,
        ])
        ->assertRedirect(route('appointment-types.index'));
});

// ---------------------------------------------------------------------------
// PUT /appointment-types/{appointmentType} — update
// ---------------------------------------------------------------------------

it('owner can update an appointment type', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Old Name',
        'is_active' => true,
    ]);

    $this->actingAs($owner)
        ->put(route('appointment-types.update', $type), atStorePayload([
            'name' => 'New Name',
            'is_active' => false,
        ]))
        ->assertRedirect(route('appointment-types.index'));

    $fresh = $type->fresh();
    expect($fresh->name)->toBe('New Name')
        ->and($fresh->is_active)->toBeFalse();
});

it('manager can update an appointment type', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    atRole($manager, 'manager', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Manager Old Name',
    ]);

    $this->actingAs($manager)
        ->put(route('appointment-types.update', $type), atStorePayload(['name' => 'Manager New Name']))
        ->assertRedirect();

    expect($type->fresh()->name)->toBe('Manager New Name');
});

it('update flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->put(route('appointment-types.update', $type), atStorePayload())
        ->assertSessionHas('toasts');
});

it('doctor role gets 403 on PUT /appointment-types/{appointmentType}', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    atRole($doctorUser, 'doctor', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($doctorUser)
        ->put(route('appointment-types.update', $type), atStorePayload())
        ->assertForbidden();
});

it('update rejects a missing name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->put(route('appointment-types.update', $type), atStorePayload(['name' => '']))
        ->assertSessionHasErrors('name');
});

it('update rejects a bad color format', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->put(route('appointment-types.update', $type), atStorePayload(['color' => 'not-a-color']))
        ->assertSessionHasErrors('color');
});

it('returns the updated type as json for a non-Inertia request', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
        'name' => 'Eski',
    ]);

    $this->actingAs($owner)
        ->putJson(route('appointment-types.update', $type), [
            'name' => 'Yeni',
            'color' => '#0D9488',
            'default_duration_minutes' => 40,
            'is_active' => true,
        ])
        ->assertOk()
        ->assertJsonPath('data.id', $type->id)
        ->assertJsonPath('data.name', 'Yeni')
        ->assertJsonPath('data.default_duration_minutes', 40);
});

// ---------------------------------------------------------------------------
// DELETE /appointment-types/{appointmentType} — soft delete
// ---------------------------------------------------------------------------

it('owner can soft-delete an appointment type', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->delete(route('appointment-types.destroy', $type))
        ->assertRedirect(route('appointment-types.index'));

    expect(AppointmentType::withoutGlobalScopes()->find($type->id)->deleted_at)->not->toBeNull();
});

it('soft delete leaves the row in the database', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->delete(route('appointment-types.destroy', $type));

    expect(AppointmentType::withoutGlobalScopes()->find($type->id))->not->toBeNull();
});

it('manager can soft-delete an appointment type', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    atRole($manager, 'manager', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($manager)
        ->delete(route('appointment-types.destroy', $type))
        ->assertRedirect();

    expect(AppointmentType::withoutGlobalScopes()->find($type->id)->deleted_at)->not->toBeNull();
});

it('doctor role gets 403 on DELETE /appointment-types/{appointmentType}', function (): void {
    $clinic = Clinic::factory()->create();
    $doctorUser = User::factory()->create();
    atRole($doctorUser, 'doctor', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($doctorUser)
        ->delete(route('appointment-types.destroy', $type))
        ->assertForbidden();
});

it('DELETE /appointment-types/{appointmentType} redirects with a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    atRole($owner, 'owner', $clinic->id);

    $type = AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $this->actingAs($owner)
        ->delete(route('appointment-types.destroy', $type))
        ->assertRedirect(route('appointment-types.index'))
        ->assertSessionHas('toasts');
});

// ---------------------------------------------------------------------------
// inactive() factory state and scopeActive
// ---------------------------------------------------------------------------

it('inactive factory state sets is_active to false', function (): void {
    $type = AppointmentType::factory()->inactive()->make();

    expect($type->is_active)->toBeFalse();
});

it('inactive appointment type is excluded from scopeActive', function (): void {
    $clinic = Clinic::factory()->create();
    AppointmentType::factory()->inactive()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);
    AppointmentType::factory()->create([
        'clinic_id' => $clinic->id,
        'vertical_id' => $clinic->vertical_id,
    ]);

    $activeCount = AppointmentType::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->active()
        ->count();

    expect($activeCount)->toBe(1);
});
