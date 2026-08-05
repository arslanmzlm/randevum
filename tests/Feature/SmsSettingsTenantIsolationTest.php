<?php

use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\User;
use App\Modules\Messaging\Contracts\SmsTemplateRendererContract;
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
 * Assign a clinic-scoped role to a user (Spatie Teams: clinic_id on assignment).
 */
function smsIsoRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a settings payload with all types enabled.
 *
 * @return array<string, bool>
 */
function smsIsoAllEnabled(): array
{
    $settings = [];
    foreach (SmsType::clinicScopedCases() as $type) {
        $settings[$type->value] = true;
    }

    return $settings;
}

// ---------------------------------------------------------------------------
// GET isolation — clinic A sees only its own settings
// ---------------------------------------------------------------------------

it("owner A's GET sees only clinic A settings, not clinic B's", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    smsIsoRole($ownerA, 'owner', $clinicA->id);

    // Clinic B has reminder_24h disabled.
    ClinicSmsSetting::factory()->forType(SmsType::Reminder24h)->disabled()->create([
        'clinic_id' => $clinicB->id,
    ]);

    // Clinic A has no preference rows — should default ON.
    $this->actingAs($ownerA)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sms.settings.reminder_24h', true) // clinic A default ON, not B's disabled
        );
});

it("owner A's settings page never exposes clinic B preference rows in the response", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    smsIsoRole($ownerA, 'owner', $clinicA->id);
    smsIsoRole($ownerB, 'owner', $clinicB->id);

    // Clinic B disables appointment_created.
    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCreated)->disabled()->create([
        'clinic_id' => $clinicB->id,
    ]);

    // Clinic A sets it explicitly enabled.
    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCreated)->create([
        'clinic_id' => $clinicA->id,
        'enabled' => true,
    ]);

    $this->actingAs($ownerA)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sms.settings.appointment_created', true)
        );
});

// ---------------------------------------------------------------------------
// PUT isolation — clinic A cannot write to clinic B's settings
// ---------------------------------------------------------------------------

it("owner A's PUT writes to clinic A rows only; clinic B's rows are untouched", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    smsIsoRole($ownerA, 'owner', $clinicA->id);
    smsIsoRole($ownerB, 'owner', $clinicB->id);

    // Clinic B has reminder_1h enabled.
    ClinicSmsSetting::factory()->forType(SmsType::Reminder1h)->create([
        'clinic_id' => $clinicB->id,
        'enabled' => true,
    ]);

    // Owner A submits with reminder_1h disabled.
    $payload = smsIsoAllEnabled();
    $payload[SmsType::Reminder1h->value] = false;

    $this->actingAs($ownerA)
        ->put(route('clinic.sms-settings.update'), ['settings' => $payload])
        ->assertRedirect();

    // Clinic A's row should be created/updated disabled.
    $rowA = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('sms_type', SmsType::Reminder1h->value)
        ->first();
    expect($rowA)->not->toBeNull()->and($rowA->enabled)->toBeFalse();

    // Clinic B's row must remain enabled.
    $rowB = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinicB->id)
        ->where('sms_type', SmsType::Reminder1h->value)
        ->first();
    expect($rowB)->not->toBeNull()->and($rowB->enabled)->toBeTrue();
});

it('owner B PUT cannot overwrite owner A rows even indirectly', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    smsIsoRole($ownerA, 'owner', $clinicA->id);
    smsIsoRole($ownerB, 'owner', $clinicB->id);

    // Clinic A disables balance_reminder.
    ClinicSmsSetting::factory()->forType(SmsType::BalanceReminder)->disabled()->create([
        'clinic_id' => $clinicA->id,
    ]);

    // Owner B submits with balance_reminder enabled.
    $payload = smsIsoAllEnabled();

    $this->actingAs($ownerB)
        ->put(route('clinic.sms-settings.update'), ['settings' => $payload])
        ->assertRedirect();

    // Clinic A's disabled row must stay disabled.
    $rowA = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('sms_type', SmsType::BalanceReminder->value)
        ->first();
    expect($rowA->enabled)->toBeFalse();
});

// ---------------------------------------------------------------------------
// Templates — GET isolation
// ---------------------------------------------------------------------------

it("owner A's GET templates prop never exposes clinic B's custom template", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    smsIsoRole($ownerA, 'owner', $clinicA->id);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->withTemplate('Clinic B özel metni')
        ->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sms.templates.appointment_created', null) // clinic A has no row — null, not B's text
        );
});

// ---------------------------------------------------------------------------
// Templates — PUT isolation
// ---------------------------------------------------------------------------

it("owner A's PUT template writes to clinic A's row only; clinic B's template row is untouched", function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    $ownerA = User::factory()->create();
    $ownerB = User::factory()->create();
    smsIsoRole($ownerA, 'owner', $clinicA->id);
    smsIsoRole($ownerB, 'owner', $clinicB->id);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->withTemplate('Clinic B orijinal metni')
        ->create(['clinic_id' => $clinicB->id]);

    $this->actingAs($ownerA)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => smsIsoAllEnabled(),
            'templates' => [SmsType::AppointmentCreated->value => 'Clinic A özel metni'],
        ])
        ->assertRedirect();

    $rowA = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinicA->id)
        ->where('sms_type', SmsType::AppointmentCreated->value)
        ->first();
    expect($rowA->template)->toBe('Clinic A özel metni');

    // Clinic B's template must remain untouched — clinic A cannot mutate it.
    $rowB = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinicB->id)
        ->where('sms_type', SmsType::AppointmentCreated->value)
        ->first();
    expect($rowB->template)->toBe('Clinic B orijinal metni');
});

it('a render for clinic A uses A\'s template, never clinic B\'s, even when both have custom text for the same type', function (): void {
    $clinicA = Clinic::factory()->create(['locale' => 'tr_TR']);
    $clinicB = Clinic::factory()->create(['locale' => 'tr_TR']);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->withTemplate('Clinic A özel metni')
        ->create(['clinic_id' => $clinicA->id]);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->withTemplate('Clinic B özel metni')
        ->create(['clinic_id' => $clinicB->id]);

    $renderer = app(SmsTemplateRendererContract::class);

    $bodyA = $renderer->resolve($clinicA, SmsType::AppointmentCreated, []);
    $bodyB = $renderer->resolve($clinicB, SmsType::AppointmentCreated, []);

    expect($bodyA)->toBe('Clinic A özel metni')
        ->and($bodyB)->toBe('Clinic B özel metni')
        ->and($bodyA)->not->toBe($bodyB);
});

// ---------------------------------------------------------------------------
// ClinicScope — a BelongsToClinic query never leaks across tenants
// ---------------------------------------------------------------------------

it('ClinicSmsSetting global scope filters by active clinic', function (): void {
    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    // Seed one row per clinic.
    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCreated)->create([
        'clinic_id' => $clinicA->id,
    ]);
    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCreated)->create([
        'clinic_id' => $clinicB->id,
    ]);

    // Activate clinic A's context.
    app(ClinicContext::class)->set($clinicA->id);

    // Default scoped query should return only clinic A's row.
    expect(ClinicSmsSetting::count())->toBe(1)
        ->and(ClinicSmsSetting::first()->clinic_id)->toBe($clinicA->id);
});
