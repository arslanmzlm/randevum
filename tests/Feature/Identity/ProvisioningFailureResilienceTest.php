<?php

use App\Models\Clinic;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vertical;
use App\Modules\Compliance\Contracts\ConsentRecorderContract;
use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Identity\Services\BranchProvisioningService;
use App\Modules\Identity\Services\ClinicRegistrationService;
use Database\Seeders\CountrySeeder;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class, CountrySeeder::class, VerticalSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $this->vertical = Vertical::where('is_active', true)->first();

    // Registration records consent to these platform docs inside the txn (see RegisterTest).
    $docAuthor = User::factory()->create();
    foreach (ConsentRecorderContract::REGISTRATION_DOCUMENT_TYPES as $type) {
        LegalDocument::factory()->ofType($type)->create(['created_by' => $docAuthor->id]);
    }
});

function resilienceTestValidPayload(int $verticalId): array
{
    return [
        'first_name' => 'Ali',
        'last_name' => 'Yılmaz',
        'email' => 'ali@example.com',
        'password' => 'password12345',
        'vertical_id' => $verticalId,
        'clinic_name' => 'Test Klinik',
    ];
}

// ---------------------------------------------------------------------------
// ClinicRegistrationService — provisioning listener failure must not crash signup
// ---------------------------------------------------------------------------

it('register() retries a transiently failing provisioning listener and completes without throwing', function (): void {
    $attempts = 0;
    Event::listen(ClinicRegistered::class, function () use (&$attempts): void {
        $attempts++;
        if ($attempts === 1) {
            throw new RuntimeException('transient provisioning failure');
        }
    });

    $user = app(ClinicRegistrationService::class)->register(resilienceTestValidPayload($this->vertical->id));

    expect($user)->toBeInstanceOf(User::class)
        ->and($attempts)->toBe(2)
        ->and(Tenant::count())->toBe(1)
        ->and(Clinic::count())->toBe(1);
});

it('register() swallows a permanently failing provisioning listener — tenant/clinic/user still commit, no exception escapes', function (): void {
    Event::listen(ClinicRegistered::class, function (): void {
        throw new RuntimeException('provisioning is broken');
    });

    $user = app(ClinicRegistrationService::class)->register(resilienceTestValidPayload($this->vertical->id));

    expect($user)->toBeInstanceOf(User::class)
        ->and(Tenant::count())->toBe(1)
        ->and(Clinic::count())->toBe(1)
        ->and(User::where('email', 'ali@example.com')->exists())->toBeTrue();
});

it('POST /register redirects normally (no 500) even when clinic provisioning fails permanently', function (): void {
    Event::listen(ClinicRegistered::class, function (): void {
        throw new RuntimeException('provisioning is broken');
    });

    $this->post(route('register.store'), [
        ...resilienceTestValidPayload($this->vertical->id),
        'password_confirmation' => 'password12345',
        'terms' => true,
        'dpa' => true,
    ])->assertRedirect(route('dashboard'));

    expect(Clinic::count())->toBe(1);
});

// ---------------------------------------------------------------------------
// BranchProvisioningService — same guard on the branch-creation path
// ---------------------------------------------------------------------------

it('BranchProvisioningService::create swallows a permanently failing listener — branch still commits, no exception escapes', function (): void {
    Event::listen(ClinicRegistered::class, function (): void {
        throw new RuntimeException('provisioning is broken');
    });

    $source = Clinic::factory()->create(['vertical_id' => $this->vertical->id]);
    $owner = User::factory()->create();

    $branch = app(BranchProvisioningService::class)->create($owner, $source, [
        'name' => 'İkinci Şube',
        'vertical_id' => $this->vertical->id,
        'copy_catalog' => false,
    ]);

    expect($branch)->toBeInstanceOf(Clinic::class)
        ->and(Clinic::where('name', 'İkinci Şube')->exists())->toBeTrue();
});

it('POST settings.branches.store redirects normally (no 500) even when provisioning fails permanently', function (): void {
    Event::listen(ClinicRegistered::class, function (): void {
        throw new RuntimeException('provisioning is broken');
    });

    $source = Clinic::factory()->create(['vertical_id' => $this->vertical->id]);
    $owner = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($source->id);
    $owner->assignRole('owner');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $owner->unsetRelation('roles');
    $owner->unsetRelation('permissions');

    $this->actingAs($owner)
        ->post(route('settings.branches.store'), [
            'name' => 'Üçüncü Şube',
            'vertical_id' => $this->vertical->id,
            'copy_catalog' => false,
        ])
        ->assertRedirect(route('settings.branches.index'));

    expect(Clinic::where('name', 'Üçüncü Şube')->exists())->toBeTrue();
});
