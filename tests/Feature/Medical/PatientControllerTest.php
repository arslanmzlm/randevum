<?php

use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
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

/**
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function ptcRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a valid POST /patients payload.
 *
 * @return array<string, mixed>
 */
function ptcPayload(array $overrides = []): array
{
    return array_merge([
        'first_name' => 'Ayşe',
        'last_name' => 'Yılmaz',
        'phone' => '05312345678',
        'contact_phone' => null,
        'email' => null,
        'birth_date' => null,
        'gender' => null,
        'notification_enabled' => true,
        'is_legacy' => false,
        'notes' => null,
    ], $overrides);
}

// ---------------------------------------------------------------------------
// GET /patients — access control + rendering
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /patients', function (): void {
    $this->get(route('patients.index'))
        ->assertRedirect(route('login'));
});

it('owner can access GET /patients and the Index component is rendered', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Index')
            ->has('patients')
            ->has('query')
            ->has('auth.permissions')
        );
});

it('assistant can access GET /patients (read-only, canManage false)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Index')
            ->where('auth.permissions', fn ($p) => ! $p->contains('patients.create'))
        );
});

it('index canManage is true for owner and false for assistant', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);
    ptcRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('patients.create')));

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($assistant)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('patients.create')));
});

it('index canDelete follows the delete permission (true for doctor, false for assistant)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($doctor, 'doctor', $clinic->id);
    ptcRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($doctor)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => $p->contains('patients.delete')));

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($assistant)
        ->get(route('patients.index'))
        ->assertInertia(fn ($page) => $page->where('auth.permissions', fn ($p) => ! $p->contains('patients.delete')));
});

// ---------------------------------------------------------------------------
// Server-side list — search / sort / filter / pagination meta
// ---------------------------------------------------------------------------

it('index returns pagination meta (total / per_page / current_page / last_page)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('patients.meta.total')
            ->has('patients.meta.per_page')
            ->has('patients.meta.current_page')
            ->has('patients.meta.last_page')
            ->where('patients.meta.total', 3)
        );
});

it('index search filters by first_name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ahmet', 'last_name' => 'Demir', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'last_name' => 'Kaya', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['search' => 'Ahmet']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('patients.meta.total', 1)
            ->where('patients.data.0.first_name', 'Ahmet')
        );
});

it('index search filters by last_name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ali', 'last_name' => 'Çelik', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Veli', 'last_name' => 'Demir', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['search' => 'Çelik']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 1));
});

it('index search also finds a soft-deleted patient', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $deleted = Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Silinen',
        'last_name' => 'Hasta',
        'phone' => '05311111111',
    ]);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'last_name' => 'Kaya', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['search' => 'Silinen']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('patients.meta.total', 1)
            ->where('patients.data.0.id', $deleted->id)
            ->where('patients.data.0.is_deleted', true)
        );
});

it('index without a search term excludes soft-deleted patients', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->trashed()->create(['clinic_id' => $clinic->id, 'first_name' => 'Silinen', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('patients.meta.total', 1)
            ->where('patients.data.0.first_name', 'Fatma')
            ->where('patients.data.0.is_deleted', false)
        );
});

it('index search that matches no live patient still excludes unrelated soft-deleted ones', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->trashed()->create(['clinic_id' => $clinic->id, 'first_name' => 'Silinen', 'phone' => '05311111111']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['search' => 'Bulunamaz']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 0));
});

it('index returns all when search term is empty', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->count(4)->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['search' => '']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 4));
});

it('index sort_field + sort_order=1 orders ascending by first_name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Zeynep', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['sort' => 'first_name']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('patients.data.0.first_name', 'Ayşe')
            ->where('patients.data.1.first_name', 'Zeynep')
        );
});

it('index sort_field + sort_order=-1 orders descending by first_name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ayşe', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Zeynep', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['sort' => '-first_name']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patients.data.0.first_name', 'Zeynep'));
});

it('index gender filter returns only matching patients', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'female', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'male', 'phone' => '05322222222']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'gender' => 'female', 'phone' => '05333333333']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['gender' => 'female']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 2));
});

it('index is_legacy filter returns only legacy patients', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->legacy()->create(['clinic_id' => $clinic->id, 'phone' => '05311111111']);
    Patient::factory()->legacy()->create(['clinic_id' => $clinic->id, 'phone' => '05322222222']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05333333333']);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['is_legacy' => '1']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('patients.meta.total', 2));
});

it('index filters prop reflects the submitted search value', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('patients.index', ['filter' => ['search' => 'test-term']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('query.filter.search', 'test-term'));
});

it('index lists only the active clinic\'s patients (ClinicScope)', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinicA->id);

    Patient::factory()->create(['clinic_id' => $clinicA->id, 'first_name' => 'ClinicAPatient', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinicB->id, 'first_name' => 'ClinicBPatient', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->get(route('patients.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('patients.meta.total', 1)
            ->where('patients.data.0.first_name', 'ClinicAPatient')
        );
});

// ---------------------------------------------------------------------------
// GET /patients/create — access control + rendering
// ---------------------------------------------------------------------------

it('owner can access GET /patients/create and Create component renders', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('patients.create'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('patients/Create'));
});

it('doctor can access GET /patients/create', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    ptcRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->get(route('patients.create'))
        ->assertOk();
});

it('assistant gets 403 on GET /patients/create', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('patients.create'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// GET /patients/{patient} — show
// ---------------------------------------------------------------------------

it('owner can access the show page and Show component renders', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Show')
            ->has('patient')
            ->where('patient.id', $patient->id)
            ->has('treatments')
            ->has('auth.permissions')
        );
});

it('assistant can view a patient (patients.view permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->get(route('patients.show', $patient))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// GET /patients/{patient}/edit — access control + rendering
// ---------------------------------------------------------------------------

it('owner can access the edit page and Edit component renders', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('patients.edit', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('patients/Edit')
            ->where('patient.id', $patient->id)
        );
});

it('assistant gets 403 on GET /patients/{patient}/edit', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->get(route('patients.edit', $patient))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// POST /patients — store
// ---------------------------------------------------------------------------

it('owner can create a patient and is redirected to show', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $response = $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload());

    $patient = Patient::withoutGlobalScopes()->where('first_name', 'Ayşe')->first();
    expect($patient)->not->toBeNull();
    $response->assertRedirect(route('patients.show', $patient));
});

it('store auto-sets clinic_id from ClinicContext', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['first_name' => 'ClinicCheck']));

    $patient = Patient::withoutGlobalScopes()->where('first_name', 'ClinicCheck')->first();

    expect($patient)->not->toBeNull()
        ->and($patient->clinic_id)->toBe($clinic->id);
});

it('store always sets user_id to null (MVP)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['first_name' => 'NullUser']));

    $patient = Patient::withoutGlobalScopes()->where('first_name', 'NullUser')->first();

    expect($patient)->not->toBeNull()
        ->and($patient->user_id)->toBeNull();
});

it('store defaults is_legacy to false when not provided', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['first_name' => 'NotLegacy', 'is_legacy' => false]));

    $patient = Patient::withoutGlobalScopes()->where('first_name', 'NotLegacy')->first();

    expect($patient)->not->toBeNull()
        ->and($patient->is_legacy)->toBeFalse();
});

it('store persists is_legacy=true when submitted', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['first_name' => 'IsLegacy', 'is_legacy' => true]));

    $patient = Patient::withoutGlobalScopes()->where('first_name', 'IsLegacy')->first();

    expect($patient)->not->toBeNull()
        ->and($patient->is_legacy)->toBeTrue();
});

it('store flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload())
        ->assertSessionHas('toasts');
});

it('doctor can create a patient (patients.create permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    ptcRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->post(route('patients.store'), ptcPayload())
        ->assertRedirect();

    expect(Patient::withoutGlobalScopes()->where('first_name', 'Ayşe')->exists())->toBeTrue();
});

it('assistant gets 403 on POST /patients', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->post(route('patients.store'), ptcPayload())
        ->assertForbidden();
});

it('receptionist can create a patient', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    ptcRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->post(route('patients.store'), ptcPayload())
        ->assertRedirect();

    expect(Patient::withoutGlobalScopes()->where('first_name', 'Ayşe')->exists())->toBeTrue();
});

// ---------------------------------------------------------------------------
// Validation — store
// ---------------------------------------------------------------------------

it('store rejects missing first_name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['first_name' => '']))
        ->assertSessionHasErrors('first_name');
});

it('store rejects missing last_name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['last_name' => '']))
        ->assertSessionHasErrors('last_name');
});

it('store accepts a patient without a phone', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['first_name' => 'NoPhone', 'phone' => '']))
        ->assertSessionHasNoErrors();

    $patient = Patient::withoutGlobalScopes()->where('first_name', 'NoPhone')->first();

    expect($patient)->not->toBeNull()
        ->and($patient->phone)->toBeNull();
});

it('store rejects an invalid phone:TR number', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['phone' => '12345']))
        ->assertSessionHasErrors('phone');
});

it('store rejects a duplicate phone among active patients in the same clinic', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05312345678']);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['phone' => '05312345678']))
        ->assertSessionHasErrors('phone');
});

it('store accepts the same phone used by a patient in a different clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinicA->id);

    Patient::factory()->create(['clinic_id' => $clinicB->id, 'phone' => '05312345678']);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['phone' => '05312345678']))
        ->assertRedirect();

    expect(Patient::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('first_name', 'Ayşe')
        ->exists()
    )->toBeTrue();
});

it('store rejects a birth_date in the future', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['birth_date' => now()->addYear()->format('Y-m-d')]))
        ->assertSessionHasErrors('birth_date');
});

it('store rejects an invalid gender value', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['gender' => 'invalid_gender']))
        ->assertSessionHasErrors('gender');
});

// ---------------------------------------------------------------------------
// Restore-on-reuse — trashed phone conflict
// ---------------------------------------------------------------------------

it('store with a trashed patient phone redirects back with restorable_patient session flash', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Silinen',
        'last_name' => 'Hasta',
        'phone' => '05312345678',
    ]);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['phone' => '05312345678']))
        ->assertRedirect()
        ->assertSessionHas('restorable_patient');

    // No new active patient should be created
    expect(
        Patient::withoutGlobalScopes()
            ->where('clinic_id', $clinic->id)
            ->whereNull('deleted_at')
            ->exists()
    )->toBeFalse();
});

it('restorable_patient flash contains the trashed patient id and full_name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $trashed = Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Silinen',
        'last_name' => 'Hasta',
        'phone' => '05312345678',
    ]);

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['phone' => '05312345678']))
        ->assertSessionHas('restorable_patient', fn ($value) => $value['id'] === $trashed->id &&
            $value['full_name'] === 'Silinen Hasta'
        );
});

it('store trashed phone conflict does not create a new patient record', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'phone' => '05312345678',
    ]);

    $countBefore = Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count();

    $this->actingAs($owner)
        ->post(route('patients.store'), ptcPayload(['phone' => '05312345678']));

    expect(Patient::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count())->toBe($countBefore);
});

// ---------------------------------------------------------------------------
// POST /patients/{patient}/restore
// ---------------------------------------------------------------------------

it('POST restore un-deletes the patient and redirects to show', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $trashed = Patient::factory()->trashed()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('patients.restore', ['patient' => $trashed->id]))
        ->assertRedirect(route('patients.show', $trashed->id));

    expect(Patient::withoutGlobalScopes()->find($trashed->id)->deleted_at)->toBeNull();
});

it('restore flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $trashed = Patient::factory()->trashed()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->post(route('patients.restore', ['patient' => $trashed->id]))
        ->assertSessionHas('toasts');
});

it('assistant gets 403 on POST /patients/{patient}/restore', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($assistant, 'assistant', $clinic->id);

    $trashed = Patient::factory()->trashed()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->post(route('patients.restore', ['patient' => $trashed->id]))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// PUT /patients/{patient} — update
// ---------------------------------------------------------------------------

it('owner can update a patient', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Eski', 'phone' => '05312345678']);

    $this->actingAs($owner)
        ->put(route('patients.update', $patient), ptcPayload(['first_name' => 'Yeni', 'phone' => '05312345678']))
        ->assertRedirect(route('patients.show', $patient));

    expect($patient->fresh()->first_name)->toBe('Yeni');
});

it('update flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05312345678']);

    $this->actingAs($owner)
        ->put(route('patients.update', $patient), ptcPayload(['phone' => '05312345678']))
        ->assertSessionHas('toasts');
});

it('update allows keeping the same phone on the same patient (unique ignore)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05312345678']);

    $this->actingAs($owner)
        ->put(route('patients.update', $patient), ptcPayload(['phone' => '05312345678']))
        ->assertRedirect();
});

it('update rejects a duplicate phone taken by another active patient', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05312345678']);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '05399999999']);

    $this->actingAs($owner)
        ->put(route('patients.update', $patient), ptcPayload(['phone' => '05312345678']))
        ->assertSessionHasErrors('phone');
});

it('assistant gets 403 on PUT /patients/{patient}', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->put(route('patients.update', $patient), ptcPayload())
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// DELETE /patients/{patient} — soft delete
// ---------------------------------------------------------------------------

it('owner can soft-delete a patient via DELETE', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->delete(route('patients.destroy', $patient))
        ->assertRedirect(route('patients.index'));

    $this->assertSoftDeleted('patients', ['id' => $patient->id]);
});

it('soft delete leaves the row in the database', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->delete(route('patients.destroy', $patient));

    expect(Patient::withoutGlobalScopes()->find($patient->id))->not->toBeNull();
});

it('destroy flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->delete(route('patients.destroy', $patient))
        ->assertSessionHas('toasts');
});

it('a blocked delete flashes a warning toast and leaves the error bag empty', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    ptcRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    // A future active appointment blocks deletion.
    Appointment::factory()->create([
        'clinic_id' => $clinic->id,
        'patient_id' => $patient->id,
        'doctor_id' => Doctor::factory()->create(['clinic_id' => $clinic->id]),
    ]);

    $this->actingAs($owner)
        ->delete(route('patients.destroy', $patient))
        ->assertSessionHasNoErrors()
        ->assertSessionHas('toasts');

    $toasts = session('toasts');
    expect($toasts)->toHaveCount(1)
        ->and($toasts[0]['severity'])->toBe('warn');

    expect(Patient::withoutGlobalScopes()->find($patient->id)->deleted_at)->toBeNull();
});

it('receptionist can soft-delete a patient', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    ptcRole($receptionist, 'receptionist', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($receptionist)
        ->delete(route('patients.destroy', $patient))
        ->assertRedirect();

    $this->assertSoftDeleted('patients', ['id' => $patient->id]);
});

it('manager can soft-delete a patient', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    ptcRole($manager, 'manager', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($manager)
        ->delete(route('patients.destroy', $patient))
        ->assertRedirect();

    $this->assertSoftDeleted('patients', ['id' => $patient->id]);
});

it('doctor can soft-delete a patient', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    ptcRole($doctor, 'doctor', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($doctor)
        ->delete(route('patients.destroy', $patient))
        ->assertRedirect();

    $this->assertSoftDeleted('patients', ['id' => $patient->id]);
});

it('assistant gets 403 on DELETE /patients/{patient} (no delete permission)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    ptcRole($assistant, 'assistant', $clinic->id);

    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $this->actingAs($assistant)
        ->delete(route('patients.destroy', $patient))
        ->assertForbidden();
});
