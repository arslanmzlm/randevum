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

/**
 * Assign a clinic-scoped Spatie Teams role to a user.
 */
function pstRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// Authentication / access control
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /patients/search', function (): void {
    $this->get(route('patients.search'))
        ->assertRedirect(route('login'));
});

it('user without patients.viewAny gets 403', function (): void {
    // No clinic role → SetClinicContext resolves null team → no patients.viewAny permission
    $noRole = User::factory()->create();

    $this->actingAs($noRole)
        ->getJson(route('patients.search', ['q' => 'test']))
        ->assertForbidden();
});

it('owner can access GET /patients/search', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'xx']))
        ->assertOk();
});

it('assistant can access GET /patients/search (has patients.viewAny)', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    pstRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->getJson(route('patients.search', ['q' => 'xx']))
        ->assertOk();
});

// ---------------------------------------------------------------------------
// Response structure
// ---------------------------------------------------------------------------

it('returns JSON with a data array', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ahmet',
        'last_name' => 'Demir',
        'phone' => '05311111111',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Ahmet']))
        ->assertOk()
        ->assertJsonStructure(['data' => [['id', 'full_name', 'phone']]]);
});

it('result id is cast to integer', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    $patient = Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ayşe',
        'phone' => '05311111111',
    ]);

    $response = $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Ayşe']));

    $response->assertOk();
    expect($response->json('data.0.id'))->toBe($patient->id)
        ->and($response->json('data.0.id'))->toBeInt();
});

it('result full_name is first_name + space + last_name', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Fatma',
        'last_name' => 'Kaya',
        'phone' => '05311111111',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Fatma']))
        ->assertOk()
        ->assertJsonPath('data.0.full_name', 'Fatma Kaya');
});

// ---------------------------------------------------------------------------
// Name matching
// ---------------------------------------------------------------------------

it('matches by first_name fragment', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ahmet', 'last_name' => 'Demir', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Fatma', 'last_name' => 'Kaya', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Ahmet']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Ahmet Demir');
});

it('matches by last_name fragment', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ali', 'last_name' => 'Çelik', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Veli', 'last_name' => 'Demir', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Çelik']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Ali Çelik');
});

it('name match is case-insensitive', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Mehmet', 'phone' => '05311111111']);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'mehmet']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('name match works on a mid-word substring', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Abdullah', 'phone' => '05311111111']);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'dull']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('matches by full name "first last" spanning both columns', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Mehmet', 'last_name' => 'Yılmaz', 'phone' => '05311111111']);
    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Mehmet', 'last_name' => 'Demir', 'phone' => '05322222222']);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Mehmet Yılmaz']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Mehmet Yılmaz');
});

// ---------------------------------------------------------------------------
// Turkish case folding — dotted/dotless i and the other diacritics
// ---------------------------------------------------------------------------

it('finds a name spelled with I when the query uses ı (and the other way round)', function (string $query): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Irmak',
        'last_name' => 'Dicle',
        'phone' => '05311111111',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => $query]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.full_name', 'Irmak Dicle');
})->with(['ırmak', 'irmak', 'Irmak', 'IRMAK', 'İrmak']);

it('folds the remaining Turkish letters in both the term and the stored name', function (string $query): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Gülşah',
        'last_name' => 'Öztürk',
        'phone' => '05322222222',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => $query]))
        ->assertOk()
        ->assertJsonCount(1, 'data');
})->with(['gülşah', 'GÜLŞAH', 'gulsah', 'oztürk', 'ozturk']);

// ---------------------------------------------------------------------------
// Phone matching — stored E.164 (+90…) found by local-format queries
// ---------------------------------------------------------------------------

it('finds patient by phone with local 05 prefix (q=05551234)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    // Cast stores this as +905551234567
    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'PhoneTest',
        'phone' => '05551234567',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => '05551234']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('finds patient by phone without leading zero (q=5551234)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'PhoneTest',
        'phone' => '05551234567',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => '5551234']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('finds patient by phone with spaces in query (q=555 123)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'PhoneTest',
        'phone' => '05551234567',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => '555 123']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// Short / blank q → empty result
// ---------------------------------------------------------------------------

it('blank q returns empty data', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => '']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('single character q returns empty data', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'A']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('missing q parameter returns empty data', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->count(3)->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->getJson(route('patients.search'))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('two-character q triggers a search', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->create(['clinic_id' => $clinic->id, 'first_name' => 'Ali', 'phone' => '05311111111']);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Al']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// Soft-deleted patients are excluded
// ---------------------------------------------------------------------------

it('soft-deleted patients are excluded from results', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Silinen',
        'last_name' => 'Hasta',
        'phone' => '05311111111',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Silinen']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('soft-deleted patient is excluded while an active patient with the same name is returned', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    Patient::factory()->trashed()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ortak',
        'phone' => '05311111111',
    ]);
    Patient::factory()->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Ortak',
        'phone' => '05322222222',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Ortak']))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

// ---------------------------------------------------------------------------
// Result cap — at most 10
// ---------------------------------------------------------------------------

it('returns at most 10 results even when more than 10 patients match', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    pstRole($owner, 'owner', $clinic->id);

    // 12 patients all sharing the same first_name; factory generates unique phones
    Patient::factory()->count(12)->create([
        'clinic_id' => $clinic->id,
        'first_name' => 'Zeynep',
    ]);

    $this->actingAs($owner)
        ->getJson(route('patients.search', ['q' => 'Zeynep']))
        ->assertOk()
        ->assertJsonCount(10, 'data');
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (mandatory)
// ---------------------------------------------------------------------------

it('clinic A search does not return clinic B patients matching the term', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    pstRole($ownerA, 'owner', $clinicA->id);

    Patient::factory()->create([
        'clinic_id' => $clinicB->id,
        'first_name' => 'UniqueSearchName',
        'last_name' => 'ClinicB',
        'phone' => '05311111111',
    ]);

    $this->actingAs($ownerA)
        ->getJson(route('patients.search', ['q' => 'UniqueSearchName']))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('clinic A search response body does not contain clinic B patient name', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    pstRole($ownerA, 'owner', $clinicA->id);

    Patient::factory()->create([
        'clinic_id' => $clinicB->id,
        'first_name' => 'SecretPatientXyz',
        'phone' => '05311111111',
    ]);

    $response = $this->actingAs($ownerA)
        ->getJson(route('patients.search', ['q' => 'SecretPatientXyz']));

    expect($response->content())->not->toContain('SecretPatientXyz');
});

it('clinic A returns own matching patients only, not clinic B patients for the same term', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    pstRole($ownerA, 'owner', $clinicA->id);

    $patientA = Patient::factory()->create([
        'clinic_id' => $clinicA->id,
        'first_name' => 'CommonName',
        'phone' => '05311111111',
    ]);
    Patient::factory()->create([
        'clinic_id' => $clinicB->id,
        'first_name' => 'CommonName',
        'phone' => '05322222222',
    ]);

    $response = $this->actingAs($ownerA)
        ->getJson(route('patients.search', ['q' => 'CommonName']));

    $response->assertOk()->assertJsonCount(1, 'data');
    expect($response->json('data.0.id'))->toBe($patientA->id);
});
