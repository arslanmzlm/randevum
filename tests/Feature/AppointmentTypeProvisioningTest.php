<?php

use App\Models\AppointmentType;
use App\Models\Clinic;
use App\Models\Vertical;
use App\Modules\Identity\Events\ClinicRegistered;
use App\Modules\Scheduling\Services\AppointmentTypeService;
use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryAppointmentTypesSeeder;
use Database\Seeders\CountrySeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, CountrySeeder::class, VerticalSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Use the seeded podiatry vertical so the listener can resolve config('podiatry.*').
    $this->podiatryVertical = Vertical::where('slug', 'podiatry')->firstOrFail();
});

/**
 * Create a podiatry clinic (uses the seeded vertical so the slug resolves to config('podiatry.*')).
 */
function podiatryClinic(): Clinic
{
    $vertical = Vertical::where('slug', 'podiatry')->firstOrFail();

    return Clinic::factory()->create(['vertical_id' => $vertical->id]);
}

// ---------------------------------------------------------------------------
// Runtime provisioning via ClinicRegistered event + listener
// ---------------------------------------------------------------------------

it('registering a new podiatry clinic provisions the 3 default appointment types', function (): void {
    $clinic = podiatryClinic();
    $defaults = config('podiatry.appointment_types', []);

    event(new ClinicRegistered($clinic));

    $count = AppointmentType::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->count();

    expect($count)->toBe(count($defaults));
});

it('provisioned types have name, color, and default_duration_minutes from the vertical config', function (): void {
    $clinic = podiatryClinic();

    event(new ClinicRegistered($clinic));

    $muayene = AppointmentType::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('name', 'Muayene')
        ->first();

    // Config stores colors as-is (lowercase) — provisioning bypasses the FormRequest normalizer.
    expect($muayene)->not->toBeNull()
        ->and($muayene->color)->toBe('#0d9488')
        ->and($muayene->default_duration_minutes)->toBe(30)
        ->and($muayene->is_active)->toBeTrue();
});

it('provisioned types are active and carry the clinic\'s vertical_id', function (): void {
    $clinic = podiatryClinic();

    event(new ClinicRegistered($clinic));

    $types = AppointmentType::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->get();

    foreach ($types as $type) {
        expect($type->is_active)->toBeTrue()
            ->and($type->vertical_id)->toBe($clinic->vertical_id);
    }
});

it('provisioning is idempotent — firing ClinicRegistered twice creates no duplicates', function (): void {
    $clinic = podiatryClinic();

    event(new ClinicRegistered($clinic));
    event(new ClinicRegistered($clinic));

    $count = AppointmentType::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->count();

    // Still 3 — firstOrCreate in provisionDefaults is idempotent.
    expect($count)->toBe(count(config('podiatry.appointment_types', [])));
});

it('AppointmentTypeService::provisionDefaults with an empty array provisions nothing', function (): void {
    $clinic = podiatryClinic();
    $service = app(AppointmentTypeService::class);

    $service->provisionDefaults($clinic, []);

    expect(AppointmentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count())->toBe(0);
});

it('a vertical with no appointment_types config provisions no types on ClinicRegistered', function (): void {
    // Temporarily override config so the podiatry vertical has no appointment_types key.
    config(['podiatry.appointment_types' => []]);

    $clinic = podiatryClinic();

    event(new ClinicRegistered($clinic));

    expect(AppointmentType::withoutGlobalScopes()->where('clinic_id', $clinic->id)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// PodiatryAppointmentTypesSeeder — seed-time provisioning
// ---------------------------------------------------------------------------

it('PodiatryAppointmentTypesSeeder creates default types for each podiatry clinic', function (): void {
    $clinic = podiatryClinic();

    (new PodiatryAppointmentTypesSeeder)->run();

    $count = AppointmentType::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->count();

    expect($count)->toBe(count(config('podiatry.appointment_types', [])));
});

it('PodiatryAppointmentTypesSeeder is idempotent — running twice creates no duplicates', function (): void {
    $clinic = podiatryClinic();

    (new PodiatryAppointmentTypesSeeder)->run();
    (new PodiatryAppointmentTypesSeeder)->run();

    $count = AppointmentType::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->count();

    expect($count)->toBe(count(config('podiatry.appointment_types', [])));
});

it('PodiatryAppointmentTypesSeeder does nothing when no podiatry clinics exist', function (): void {
    // Only non-podiatry clinics (or none)
    (new PodiatryAppointmentTypesSeeder)->run();

    expect(AppointmentType::withoutGlobalScopes()->count())->toBe(0);
});

it('PodiatryAppointmentTypesSeeder seeds correct names from config', function (): void {
    $clinic = podiatryClinic();

    (new PodiatryAppointmentTypesSeeder)->run();

    $names = AppointmentType::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->pluck('name')
        ->sort()
        ->values()
        ->all();

    $expected = collect(config('podiatry.appointment_types', []))->pluck('name')->sort()->values()->all();

    expect($names)->toEqual($expected);
});
