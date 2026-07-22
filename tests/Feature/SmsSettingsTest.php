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
// GET /clinic/sms-settings — templates / defaults / variables / sample props
// ---------------------------------------------------------------------------

it('templates prop contains all 5 customizable SmsType keys, null (no custom template) by default', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('templates', 5)
            ->where('templates.appointment_created', null)
            ->where('templates.appointment_cancelled', null)
            ->where('templates.appointment_rescheduled', null)
            ->where('templates.reminder_24h', null)
            ->where('templates.reminder_1h', null)
        );
});

it('templates prop does NOT include balance_reminder (not customizable)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('templates.balance_reminder')
        );
});

it('templates prop reflects a persisted custom template', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->withTemplate('Merhaba :patient, randevunuz oluşturuldu.')
        ->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('templates.appointment_created', 'Merhaba :patient, randevunuz oluşturuldu.')
        );
});

it('defaults prop contains the lang default body for all 5 customizable types', function (): void {
    $clinic = Clinic::factory()->create(['locale' => 'tr_TR']);
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('defaults.appointment_created', __('sms.appointment.created.body', [], 'tr'))
            ->where('defaults.reminder_24h', __('sms.reminder.body', [], 'tr'))
            ->where('defaults.reminder_1h', __('sms.reminder.body', [], 'tr'))
        );
});

it('variables prop is exactly the allowlist [clinic, date, time, patient, doctor]', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('variables', ['clinic', 'date', 'time', 'patient', 'doctor'])
        );
});

it('sample prop carries the clinic own name for :clinic', function (): void {
    $clinic = Clinic::factory()->create(['name' => 'Clinic Alpha']);
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.sms-settings.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sample.clinic', 'Clinic Alpha')
            ->has('sample.date')
            ->has('sample.time')
            ->has('sample.patient')
            ->has('sample.doctor')
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

// ---------------------------------------------------------------------------
// PUT /clinic/sms-settings — templates persistence
// ---------------------------------------------------------------------------

it('PUT persists a custom template for a customizable type', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [
                SmsType::AppointmentCreated->value => 'Merhaba :patient, :clinic randevunuz :date :time.',
            ],
        ])
        ->assertRedirect();

    $row = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::AppointmentCreated->value)
        ->first();

    expect($row->template)->toBe('Merhaba :patient, :clinic randevunuz :date :time.');
});

it('PUT with a templates map that omits a type leaves that type stored template unchanged', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCancelled)
        ->withTemplate('Korunması gereken özel metin')
        ->create(['clinic_id' => $clinic->id]);

    // A PUT that carries only AppointmentCreated in the templates map must NOT wipe
    // the stored AppointmentCancelled template — an absent key means "leave unchanged".
    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [SmsType::AppointmentCreated->value => 'Yeni :patient metni'],
        ])
        ->assertRedirect();

    $cancelled = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::AppointmentCancelled->value)
        ->first();

    expect($cancelled->template)->toBe('Korunması gereken özel metin');
});

it('PUT with an empty-string template resets the type to default (row template = null)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCancelled)
        ->withTemplate('Eski özel metin')
        ->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [SmsType::AppointmentCancelled->value => ''],
        ])
        ->assertRedirect();

    $row = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::AppointmentCancelled->value)
        ->first();

    expect($row->template)->toBeNull();
});

it('PUT with a whitespace-only template resets the type to default (row template = null)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCancelled)
        ->withTemplate('Eski özel metin')
        ->create(['clinic_id' => $clinic->id]);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [SmsType::AppointmentCancelled->value => '   '],
        ])
        ->assertRedirect();

    $row = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::AppointmentCancelled->value)
        ->first();

    expect($row->template)->toBeNull();
});

it('PUT rejects a template containing a non-allowlisted :token', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [
                SmsType::AppointmentCreated->value => 'Randevunuz :service için :date.',
            ],
        ])
        ->assertSessionHasErrors('templates.appointment_created');

    expect(ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::AppointmentCreated->value)
        ->exists()
    )->toBeFalse();
});

it('PUT rejects a templates key that is not a customizable SmsType (balance_reminder)', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [SmsType::BalanceReminder->value => 'Özel bakiye mesajı'],
        ])
        ->assertSessionHasErrors('templates.balance_reminder');
});

it('PUT rejects a template whose encoding-aware segment count exceeds the configured max_segments cap', function (): void {
    config(['platform.sms.max_segments' => 3]);

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    // 460 plain ASCII (GSM-7) chars → ceil(460/153) = 4 segments, over the cap of 3.
    $overCapTemplate = str_repeat('a', 460);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [SmsType::Reminder24h->value => $overCapTemplate],
        ])
        ->assertSessionHasErrors('templates.reminder_24h');

    expect(ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::Reminder24h->value)
        ->exists()
    )->toBeFalse();
});

it('PUT accepts a template at exactly the max_segments cap', function (): void {
    config(['platform.sms.max_segments' => 3]);

    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    // 459 plain ASCII (GSM-7) chars → ceil(459/153) = 3 segments, exactly at the cap.
    $atCapTemplate = str_repeat('a', 459);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [SmsType::Reminder24h->value => $atCapTemplate],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $row = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::Reminder24h->value)
        ->first();

    expect($row->template)->toBe($atCapTemplate);
});

it('the two reminder types persist independently even though they share the same lang default', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), [
            'settings' => allEnabledSettings(),
            'templates' => [
                SmsType::Reminder24h->value => '24 saatlik özel metin',
                SmsType::Reminder1h->value => '1 saatlik özel metin',
            ],
        ])
        ->assertRedirect();

    $row24h = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)->where('sms_type', SmsType::Reminder24h->value)->first();
    $row1h = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)->where('sms_type', SmsType::Reminder1h->value)->first();

    expect($row24h->template)->toBe('24 saatlik özel metin')
        ->and($row1h->template)->toBe('1 saatlik özel metin');
});

it('balance_reminder has no template path — PUT with only settings (no templates key) succeeds', function (): void {
    $clinic = Clinic::factory()->create();
    $owner = User::factory()->create();
    smsRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->put(route('clinic.sms-settings.update'), ['settings' => allEnabledSettings()])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $row = ClinicSmsSetting::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('sms_type', SmsType::BalanceReminder->value)
        ->first();

    expect($row->template)->toBeNull();
});
