<?php

use App\Models\Clinic;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use App\Modules\Compliance\Contracts\ConsentRecorderContract;
use App\Support\ClinicContext;
use Database\Seeders\CountrySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, CountrySeeder::class, VerticalSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
    $this->vertical = Vertical::where('is_active', true)->first();

    $docAuthor = User::factory()->create();
    foreach (ConsentRecorderContract::REGISTRATION_DOCUMENT_TYPES as $type) {
        LegalDocument::factory()->ofType($type)->create(['created_by' => $docAuthor->id]);
    }
});

/**
 * Build a registration payload for isolation tests.
 */
function regIsoPayload(int $verticalId, string $email, string $clinicName): array
{
    return [
        'first_name' => 'Test',
        'last_name' => 'User',
        'email' => $email,
        'password' => 'password12345',
        'password_confirmation' => 'password12345',
        'vertical_id' => $verticalId,
        'clinic_name' => $clinicName,
        'terms' => true,
        'dpa' => true,
    ];
}

/**
 * Register a clinic owner via the HTTP endpoint, then log out so the guest
 * middleware permits subsequent registrations in the same test.
 */
function regIsoRegisterAndLogout(mixed $test, int $verticalId, string $email, string $clinicName): void
{
    $test->post(route('register.store'), regIsoPayload($verticalId, $email, $clinicName));
    auth()->logout();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
}

// ---------------------------------------------------------------------------
// Two registrations produce two fully isolated tenants and clinics
// ---------------------------------------------------------------------------

it('two registrations produce two distinct tenants', function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    expect(Tenant::count())->toBe(2);

    $tenantIds = Tenant::pluck('id');
    expect($tenantIds[0])->not->toBe($tenantIds[1]);
});

it('two registrations produce two distinct clinics', function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    expect(Clinic::count())->toBe(2);

    $clinicIds = Clinic::pluck('id');
    expect($clinicIds[0])->not->toBe($clinicIds[1]);
});

it('each clinic belongs to its own tenant', function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    $clinicA = Clinic::where('name', 'Klinik A')->first();
    $clinicB = Clinic::where('name', 'Klinik B')->first();

    expect($clinicA->tenant_id)->not->toBe($clinicB->tenant_id);
});

// ---------------------------------------------------------------------------
// Role-scoping isolation (Spatie Teams)
// ---------------------------------------------------------------------------

it("owner-A's role is visible under clinic A's team but not clinic B's", function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    $ownerA = User::where('email', 'owner-a@example.com')->first();
    $clinicA = Clinic::where('name', 'Klinik A')->first();
    $clinicB = Clinic::where('name', 'Klinik B')->first();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicA->id);
    $ownerA->unsetRelation('roles');
    expect($ownerA->hasRole('owner'))->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicB->id);
    $ownerA->unsetRelation('roles');
    expect($ownerA->hasRole('owner'))->toBeFalse();
});

it("owner-B's role is visible under clinic B's team but not clinic A's", function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    $ownerB = User::where('email', 'owner-b@example.com')->first();
    $clinicA = Clinic::where('name', 'Klinik A')->first();
    $clinicB = Clinic::where('name', 'Klinik B')->first();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicB->id);
    $ownerB->unsetRelation('roles');
    expect($ownerB->hasRole('owner'))->toBeTrue();

    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicA->id);
    $ownerB->unsetRelation('roles');
    expect($ownerB->hasRole('owner'))->toBeFalse();
});

it('owner-A and owner-B each hold no cross-clinic role under the null team', function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    $ownerA = User::where('email', 'owner-a@example.com')->first();
    $ownerB = User::where('email', 'owner-b@example.com')->first();

    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $ownerA->unsetRelation('roles');
    $ownerB->unsetRelation('roles');

    expect($ownerA->hasRole('owner'))->toBeFalse()
        ->and($ownerB->hasRole('owner'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// SetClinicContext isolation — each owner resolves to their own clinic only
// ---------------------------------------------------------------------------

it('resolves clinic A context after owner-A visits the dashboard', function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');

    $ownerA = User::where('email', 'owner-a@example.com')->first();
    $clinicA = Clinic::where('name', 'Klinik A')->first();

    $this->actingAs($ownerA)->get(route('dashboard'))->assertOk();

    expect(app(ClinicContext::class)->id())->toBe($clinicA->id);
});

it('resolves clinic B context after owner-B visits the dashboard', function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    $ownerB = User::where('email', 'owner-b@example.com')->first();
    $clinicB = Clinic::where('name', 'Klinik B')->first();

    $this->actingAs($ownerB)->get(route('dashboard'))->assertOk();

    expect(app(ClinicContext::class)->id())->toBe($clinicB->id);
});

it('owner-A and owner-B each resolve to their own clinic context independently', function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    $ownerA = User::where('email', 'owner-a@example.com')->first();
    $ownerB = User::where('email', 'owner-b@example.com')->first();
    $clinicA = Clinic::where('name', 'Klinik A')->first();
    $clinicB = Clinic::where('name', 'Klinik B')->first();

    // Request as owner-A
    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->actingAs($ownerA)->get(route('dashboard'));
    expect(app(ClinicContext::class)->id())->toBe($clinicA->id);

    // Request as owner-B
    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->actingAs($ownerB)->get(route('dashboard'));
    expect(app(ClinicContext::class)->id())->toBe($clinicB->id);
});

it('owner-A context never resolves to clinic B', function (): void {
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-a@example.com', 'Klinik A');
    regIsoRegisterAndLogout($this, $this->vertical->id, 'owner-b@example.com', 'Klinik B');

    $ownerA = User::where('email', 'owner-a@example.com')->first();
    $clinicB = Clinic::where('name', 'Klinik B')->first();

    app(ClinicContext::class)->forget();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->actingAs($ownerA)->get(route('dashboard'));

    expect(app(ClinicContext::class)->id())->not->toBe($clinicB->id);
});
