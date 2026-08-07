<?php

use App\Models\Clinic;
use App\Models\FollowUpType;
use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Medical\Services\FollowUpTypeService;
use Database\Seeders\FollowUpTypeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

// ---------------------------------------------------------------------------
// Runtime provisioning via ClinicRegistered event + listener
// ---------------------------------------------------------------------------

it('registering a new clinic provisions the configured default follow-up types', function (): void {
    $clinic = Clinic::factory()->create();
    $defaults = config('platform.follow_ups.default_types', []);

    event(new ClinicRegistered($clinic));

    $count = FollowUpType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count();

    expect($count)->toBe(count($defaults));
});

it('provisioned types are system, active, and named from config', function (): void {
    $clinic = Clinic::factory()->create();

    event(new ClinicRegistered($clinic));

    $types = FollowUpType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->get();

    foreach ($types as $type) {
        expect($type->is_system)->toBeTrue()
            ->and($type->is_active)->toBeTrue();
    }

    expect($types->pluck('name')->sort()->values()->all())
        ->toEqual(collect(config('platform.follow_ups.default_types', []))->sort()->values()->all());
});

it('provisioning is idempotent — firing ClinicRegistered twice creates no duplicates', function (): void {
    $clinic = Clinic::factory()->create();

    event(new ClinicRegistered($clinic));
    event(new ClinicRegistered($clinic));

    $count = FollowUpType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count();

    expect($count)->toBe(count(config('platform.follow_ups.default_types', [])));
});

it('FollowUpTypeService::provisionDefaults with an empty array provisions nothing', function (): void {
    $clinic = Clinic::factory()->create();

    app(FollowUpTypeService::class)->provisionDefaults($clinic, []);

    expect(FollowUpType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// FollowUpTypeSeeder — backfill for pre-existing clinics
// ---------------------------------------------------------------------------

it('FollowUpTypeSeeder provisions defaults for every existing clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    (new FollowUpTypeSeeder)->run();

    $expected = count(config('platform.follow_ups.default_types', []));
    expect(FollowUpType::withoutGlobalScopes()->where('clinic_id', $clinicA->id)->count())->toBe($expected)
        ->and(FollowUpType::withoutGlobalScopes()->where('clinic_id', $clinicB->id)->count())->toBe($expected);
});

it('FollowUpTypeSeeder is idempotent — running twice creates no duplicates', function (): void {
    $clinic = Clinic::factory()->create();

    (new FollowUpTypeSeeder)->run();
    (new FollowUpTypeSeeder)->run();

    $count = FollowUpType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count();
    expect($count)->toBe(count(config('platform.follow_ups.default_types', [])));
});
