<?php

use App\Models\Clinic;
use App\Models\User;
use App\Support\ClinicContext;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role to a user (Spatie Teams: clinic_id on assignment).
 */
function clinicIsoRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * @return array<string, mixed>
 */
function clinicIsoPayload(Clinic $clinic, string $name = 'Updated Name'): array
{
    return [
        'name' => $name,
        'slug' => 'updated-slug-'.$clinic->id,
        'description' => null,
        'phone' => null,
        'email' => null,
        'website' => null,
        'country_id' => $clinic->country_id,
        'city_id' => $clinic->city_id,
        'district' => null,
        'address' => null,
        'postal_code' => null,
        'latitude' => null,
        'longitude' => null,
        'default_slot_duration_minutes' => 30,
        'auto_no_show_enabled' => true,
        'auto_no_show_grace_hours' => 2,
        'working_hours' => Clinic::defaultWorkingHours(),
    ];
}

// ---------------------------------------------------------------------------
// GET /clinic — read isolation
// ---------------------------------------------------------------------------

it("owner A's GET /clinic shows clinic A's data and not clinic B's", function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Clinic Alpha']);
    $clinicB = Clinic::factory()->create(['name' => 'Clinic Beta']);

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    clinicIsoRole($ownerA, 'owner', $clinicA->id);
    clinicIsoRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('clinic.id', $clinicA->id)
            ->where('clinic.name', 'Clinic Alpha')
        );
});

it("owner A's GET /clinic never leaks clinic B's name into the response", function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Clinic Alpha']);
    $clinicB = Clinic::factory()->create(['name' => 'Clinic Beta']);

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    clinicIsoRole($ownerA, 'owner', $clinicA->id);
    clinicIsoRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerA)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('clinic.id', $clinicA->id)
            ->whereNot('clinic.name', 'Clinic Beta')
        );
});

it('each owner independently sees only their own clinic', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Clinic Alpha']);
    $clinicB = Clinic::factory()->create(['name' => 'Clinic Beta']);

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    clinicIsoRole($ownerA, 'owner', $clinicA->id);
    clinicIsoRole($ownerB, 'owner', $clinicB->id);

    // Owner-A sees Alpha
    $this->actingAs($ownerA)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clinic.name', 'Clinic Alpha'));

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Owner-B sees Beta
    $this->actingAs($ownerB)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('clinic.name', 'Clinic Beta'));
});

// ---------------------------------------------------------------------------
// PUT /clinic — write isolation
// ---------------------------------------------------------------------------

it('owner A PUT /clinic mutates only clinic A; clinic B remains untouched', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Alpha Original']);
    $clinicB = Clinic::factory()->create(['name' => 'Beta Original']);

    $ownerA = User::factory()->create();
    clinicIsoRole($ownerA, 'owner', $clinicA->id);

    $this->actingAs($ownerA)
        ->put(route('clinic.update'), clinicIsoPayload($clinicA, 'Alpha Updated'))
        ->assertRedirect();

    expect($clinicA->fresh()->name)->toBe('Alpha Updated')
        ->and($clinicB->fresh()->name)->toBe('Beta Original');
});

it('the PUT /clinic endpoint takes no clinic id — mutation is always scoped to ClinicContext', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Alpha Original']);
    $clinicB = Clinic::factory()->create(['name' => 'Beta Original']);

    $ownerA = User::factory()->create();
    clinicIsoRole($ownerA, 'owner', $clinicA->id);

    // Owner-A submits without any clinic id reference in the payload.
    // There is no route parameter — no way to target clinic B.
    $this->actingAs($ownerA)
        ->put(route('clinic.update'), clinicIsoPayload($clinicA, 'Alpha Changed'))
        ->assertRedirect();

    expect($clinicA->fresh()->name)->toBe('Alpha Changed')
        ->and($clinicB->fresh()->name)->toBe('Beta Original');
});

it('owner B PUT /clinic cannot overwrite owner A data even indirectly', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Alpha Protected']);
    $clinicB = Clinic::factory()->create(['name' => 'Beta Original']);

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    clinicIsoRole($ownerA, 'owner', $clinicA->id);
    clinicIsoRole($ownerB, 'owner', $clinicB->id);

    // Owner-B submits their update — context resolves to clinic B only.
    $this->actingAs($ownerB)
        ->put(route('clinic.update'), clinicIsoPayload($clinicB, 'Beta Changed'))
        ->assertRedirect();

    expect($clinicB->fresh()->name)->toBe('Beta Changed')
        ->and($clinicA->fresh()->name)->toBe('Alpha Protected');
});

it('owner A PUT /clinic coordinates never leak into clinic B', function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Alpha', 'latitude' => null, 'longitude' => null]);
    $clinicB = Clinic::factory()->create(['name' => 'Beta', 'latitude' => null, 'longitude' => null]);

    $ownerA = User::factory()->create();
    clinicIsoRole($ownerA, 'owner', $clinicA->id);

    $payload = array_merge(clinicIsoPayload($clinicA, 'Alpha'), [
        'latitude' => 41.0082,
        'longitude' => 28.9784,
    ]);

    $this->actingAs($ownerA)
        ->put(route('clinic.update'), $payload)
        ->assertRedirect();

    $freshA = $clinicA->fresh();
    $freshB = $clinicB->fresh();
    expect((float) $freshA->latitude)->toBe(41.0082)
        ->and((float) $freshA->longitude)->toBe(28.9784)
        ->and($freshB->latitude)->toBeNull()
        ->and($freshB->longitude)->toBeNull();
});

it('owner A POST /clinic/media/logo_icon stores media on clinic A only', function (): void {
    Storage::fake(config('media-library.disk_name'));
    Queue::fake();

    $clinicA = Clinic::factory()->create(['name' => 'Alpha']);
    $clinicB = Clinic::factory()->create(['name' => 'Beta']);

    $ownerA = User::factory()->create();
    clinicIsoRole($ownerA, 'owner', $clinicA->id);

    $this->actingAs($ownerA)
        ->post(route('clinic.media.update', ['collection' => 'logo_icon']), [
            'image' => UploadedFile::fake()->image('icon.png', 256, 256),
        ])
        ->assertRedirect();

    expect($clinicA->fresh()->getMedia('logo_icon'))->toHaveCount(1)
        ->and($clinicB->fresh()->getMedia('logo_icon'))->toHaveCount(0);
});

it("owner B's GET /clinic never exposes clinic A's coordinates", function (): void {
    $clinicA = Clinic::factory()->create(['name' => 'Alpha', 'latitude' => 41.0082, 'longitude' => 28.9784]);
    $clinicB = Clinic::factory()->create(['name' => 'Beta', 'latitude' => null, 'longitude' => null]);

    $ownerB = User::factory()->create();
    clinicIsoRole($ownerB, 'owner', $clinicB->id);

    $this->actingAs($ownerB)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('clinic.id', $clinicB->id)
            ->where('clinic.latitude', null)
            ->where('clinic.longitude', null)
        );
});
