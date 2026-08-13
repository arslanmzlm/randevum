<?php

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentStatus;
use App\Models\AnamnesisField;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Models\Vertical;
use App\Modules\Verticals\Podiatry\Database\Seeders\PodiatryAnamnesisFieldsSeeder;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Database\Seeders\VerticalSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role (field-definition tests).
 */
function afdRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// Seeder
// ---------------------------------------------------------------------------

it('PodiatryAnamnesisFieldsSeeder creates the three podiatry definitions with clinic_id null', function (): void {
    $this->seed([VerticalSeeder::class, PodiatryAnamnesisFieldsSeeder::class]);
    $vertical = Vertical::where('slug', 'podiatry')->firstOrFail();

    // Definitions are platform rows (clinic_id null) and no clinic is active, so read
    // them the way AnamnesisFieldRepository does: scope off + explicit predicate.
    $definitions = AnamnesisField::withoutGlobalScopes()->where('vertical_id', $vertical->id)->get();

    expect($definitions)->toHaveCount(3)
        ->and($definitions->pluck('key')->sort()->values()->all())->toBe([
            'current_foot_complaint', 'diabetic_foot_history', 'foot_surgery_history',
        ])
        ->and($definitions->pluck('clinic_id')->unique()->all())->toBe([null]);
});

it('PodiatryAnamnesisFieldsSeeder is idempotent on re-run', function (): void {
    $this->seed(VerticalSeeder::class);
    $this->seed(PodiatryAnamnesisFieldsSeeder::class);
    $this->seed(PodiatryAnamnesisFieldsSeeder::class);

    expect(AnamnesisField::withoutGlobalScopes()->count())->toBe(3);
});

// ---------------------------------------------------------------------------
// Inertia props — patients.show / treatments.process expose anamnesisFields
// ---------------------------------------------------------------------------

it('patients.show exposes anamnesisFields with translated label/group, sort order, active-only', function (): void {
    $this->seed([VerticalSeeder::class, PodiatryAnamnesisFieldsSeeder::class]);
    $vertical = Vertical::where('slug', 'podiatry')->firstOrFail();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $owner = User::factory()->create();
    afdRole($owner, 'owner', $clinic->id);

    // An inactive definition must never appear in the props.
    AnamnesisField::factory()->text()->inactive()->create([
        'vertical_id' => $vertical->id,
        'key' => 'retired',
        'sort' => 5,
    ]);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('anamnesisFields', fn ($fields) => collect($fields)->pluck('key')->all() === [
                'foot_surgery_history', 'diabetic_foot_history', 'current_foot_complaint',
            ])
            ->where('anamnesisFields', fn ($fields) => collect($fields)->first()['label'] === __('health.fields.foot_surgery_history'))
            ->where('anamnesisFields', fn ($fields) => collect($fields)->first()['group'] === __('health.groups.podiatry')));
});

it('treatments.process exposes anamnesisFields active-only in sort order', function (): void {
    $this->seed([VerticalSeeder::class, PodiatryAnamnesisFieldsSeeder::class]);
    $vertical = Vertical::where('slug', 'podiatry')->firstOrFail();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $owner = User::factory()->create();
    afdRole($owner, 'owner', $clinic->id);

    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $startsAt = Carbon::now()->addDay();
    $appointment = Appointment::factory()->withStatus(AppointmentStatus::Arrived)->create([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->copy()->addMinutes(30),
    ]);

    $treatment = Treatment::create([
        'clinic_id' => $clinic->id,
        'appointment_id' => $appointment->id,
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'subtotal_amount' => 0,
        'discount_amount' => 0,
        'total_amount' => 0,
        'status' => TreatmentStatus::Draft,
        'created_by' => $owner->id,
    ]);

    $this->actingAs($owner)
        ->get(route('treatments.process', $treatment))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('treatments/Process')
            ->where('anamnesisFields', fn ($fields) => collect($fields)->pluck('key')->all() === [
                'foot_surgery_history', 'diabetic_foot_history', 'current_foot_complaint',
            ]));
});

it('patients.show exposes anamnesisFieldsAll including an inactive definition, unlike anamnesisFields', function (): void {
    $this->seed([VerticalSeeder::class, PodiatryAnamnesisFieldsSeeder::class]);
    $vertical = Vertical::where('slug', 'podiatry')->firstOrFail();
    $clinic = Clinic::factory()->create(['vertical_id' => $vertical->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $owner = User::factory()->create();
    afdRole($owner, 'owner', $clinic->id);

    AnamnesisField::factory()->text()->inactive()->create([
        'vertical_id' => $vertical->id,
        'key' => 'retired',
        'sort' => 5,
    ]);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('anamnesisFields', fn ($fields) => ! collect($fields)->pluck('key')->contains('retired'))
            ->where('anamnesisFieldsAll', fn ($fields) => collect($fields)->pluck('key')->contains('retired')));
});

it('a clinic on another vertical gets anamnesisFields: []', function (): void {
    $this->seed([VerticalSeeder::class, PodiatryAnamnesisFieldsSeeder::class]);
    $otherVertical = Vertical::factory()->create(['slug' => 'dermatology']);
    $clinic = Clinic::factory()->create(['vertical_id' => $otherVertical->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id]);

    $owner = User::factory()->create();
    afdRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('patients.show', $patient))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('anamnesisFields', []));
});
