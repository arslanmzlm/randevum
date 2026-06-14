<?php

use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
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
 * Assign a clinic-scoped role to a user (Spatie Teams: clinic_id on assignment).
 */
function smsRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Build a full settings payload with all clinic-scoped SmsType values.
 *
 * @return array<string, bool>
 */
function allEnabledSettings(): array
{
    $settings = [];
    foreach (SmsType::clinicScopedCases() as $type) {
        $settings[$type->value] = true;
    }

    return $settings;
}

// ---------------------------------------------------------------------------
// GET /clinic/sms-settings — access control
// ---------------------------------------------------------------------------

it('guest is redirected to login from GET /clinic/sms-settings', function (): void {
    $this->get(route('clinic.sms-settings.edit'))
        ->assertRedirect(route('login'));
});

it('owner can GET /clinic/sms-settings and sees the clinic/SmsSettings Inertia component', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('clinic/SmsSettings')
            ->has('settings')
            ->has('types')
        );
});

it('manager can GET /clinic/sms-settings', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    smsRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk();
});

it('receptionist can GET /clinic/sms-settings', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    smsRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk();
});

it('doctor is 403 on GET /clinic/sms-settings', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    smsRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->get(route('clinic.sms-settings.edit'))
        ->assertForbidden();
});

it('assistant is 403 on GET /clinic/sms-settings', function (): void {
    $clinic = Clinic::factory()->create();
    $assistant = User::factory()->create();
    smsRole($assistant, 'assistant', $clinic->id);

    $this->actingAs($assistant)
        ->get(route('clinic.sms-settings.edit'))
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// GET /clinic/sms-settings — Inertia props contract
// ---------------------------------------------------------------------------

it('settings prop contains all 6 clinic-scoped SmsType keys', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('settings.appointment_created')
            ->has('settings.appointment_cancelled')
            ->has('settings.appointment_rescheduled')
            ->has('settings.reminder_24h')
            ->has('settings.reminder_1h')
            ->has('settings.balance_reminder')
        );
});

it('settings prop defaults all types to true when no preference rows exist', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.appointment_created', true)
            ->where('settings.appointment_cancelled', true)
            ->where('settings.appointment_rescheduled', true)
            ->where('settings.reminder_24h', true)
            ->where('settings.reminder_1h', true)
            ->where('settings.balance_reminder', true)
        );
});

it('settings prop reflects a persisted disabled preference', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    ClinicSmsSetting::factory()->forType(SmsType::Reminder24h)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('settings.reminder_24h', false)
            ->where('settings.appointment_created', true) // others still default ON
        );
});

it('types prop contains all 6 clinic-scoped SmsType values', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('types', 6)
        );
});

// ---------------------------------------------------------------------------
// PUT /clinic/sms-settings — access control
// ---------------------------------------------------------------------------

it('owner can PUT /clinic/sms-settings and settings persist', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $payload = allEnabledSettings();
    $payload[SmsType::Reminder1h->value] = false;

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), ['settings' => $payload])
        ->assertRedirect();

    $row = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::Reminder1h->value)
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->enabled)->toBeFalse();
});

it('manager can PUT /clinic/sms-settings', function (): void {
    $clinic = Clinic::factory()->create();
    $manager = User::factory()->create();
    smsRole($manager, 'manager', $clinic->id);

    $this->actingAs($manager)
        ->put(route('clinic.sms-settings.update'), ['settings' => allEnabledSettings()])
        ->assertRedirect();
});

it('receptionist is 403 on PUT /clinic/sms-settings', function (): void {
    $clinic = Clinic::factory()->create();
    $receptionist = User::factory()->create();
    smsRole($receptionist, 'receptionist', $clinic->id);

    $this->actingAs($receptionist)
        ->put(route('clinic.sms-settings.update'), ['settings' => allEnabledSettings()])
        ->assertForbidden();
});

it('doctor is 403 on PUT /clinic/sms-settings', function (): void {
    $clinic = Clinic::factory()->create();
    $doctor = User::factory()->create();
    smsRole($doctor, 'doctor', $clinic->id);

    $this->actingAs($doctor)
        ->put(route('clinic.sms-settings.update'), ['settings' => allEnabledSettings()])
        ->assertForbidden();
});

// ---------------------------------------------------------------------------
// PUT /clinic/sms-settings — persistence + feedback
// ---------------------------------------------------------------------------

it('successful PUT flashes a success toast', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), ['settings' => allEnabledSettings()])
        ->assertSessionHas('toasts');
});

it('PUT upserts preference rows so a second PUT overwrites the first', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    // First PUT: disable AppointmentCreated.
    $first = allEnabledSettings();
    $first[SmsType::AppointmentCreated->value] = false;

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), ['settings' => $first]);

    app(ClinicContext::class)->forget();

    // Second PUT: re-enable it.
    $second = allEnabledSettings();
    $second[SmsType::AppointmentCreated->value] = true;

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), ['settings' => $second]);

    $row = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::AppointmentCreated->value)
        ->first();

    expect($row->enabled)->toBeTrue();
});

// ---------------------------------------------------------------------------
// PUT /clinic/sms-settings — validation
// ---------------------------------------------------------------------------

it('PUT rejects an unknown settings key', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $payload = allEnabledSettings();
    $payload['otp'] = true; // OTP is not clinic-scoped

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), ['settings' => $payload])
        ->assertSessionHasErrors('settings.otp');
});

it('PUT rejects when settings is missing', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [])
        ->assertSessionHasErrors('settings');
});
