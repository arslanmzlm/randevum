<?php

use App\Enums\AppointmentStatus;
use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Appointment;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SmsLog;
use App\Models\User;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Messaging\Services\SmsQuotaService;
use App\Support\ClinicContext;
use Carbon\Carbon;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed([RoleSeeder::class, PermissionSeeder::class]);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(ClinicContext::class)->forget();
});

/**
 * Assign a clinic-scoped role to a user (Spatie Teams).
 */
function quotaRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

/**
 * Insert an sms_logs row that counts toward quota (Queued / Sent / Failed).
 * Accepts an optional `created_at` to override the timestamp via a raw DB update
 * (bypasses Laravel's auto-fill so we can back-date rows reliably).
 *
 * @param  array<string, mixed>  $attributes
 */
function makeConsumedLog(Clinic $clinic, array $attributes = []): SmsLog
{
    $createdAt = $attributes['created_at'] ?? null;
    unset($attributes['created_at']);

    $log = SmsLog::create(array_merge([
        'clinic_id' => $clinic->id,
        'patient_id' => null,
        'phone' => '+905301234567',
        'type' => SmsType::AppointmentCreated->value,
        'body' => 'Test',
        'status' => SmsStatus::Sent->value,
        'error' => null,
        'scheduled_at' => null,
        'sent_at' => now(),
    ], $attributes));

    if ($createdAt !== null) {
        DB::table('sms_logs')->where('id', $log->id)->update(['created_at' => $createdAt]);
        $log->created_at = $createdAt;
    }

    return $log;
}

/**
 * Create a future appointment for the given clinic/doctor/patient.
 *
 * @param  array<string, mixed>  $overrides
 */
function quotaAppointment(Clinic $clinic, Doctor $doctor, Patient $patient, array $overrides = []): Appointment
{
    $mondayUtc = Carbon::now('Europe/Istanbul')->next(Carbon::MONDAY)->setTime(10, 0, 0)->utc();

    return Appointment::factory()->create(array_merge([
        'clinic_id' => $clinic->id,
        'doctor_id' => $doctor->id,
        'patient_id' => $patient->id,
        'status' => AppointmentStatus::Confirmed,
        'starts_at' => $mondayUtc,
        'ends_at' => $mondayUtc->copy()->addMinutes(30),
    ], $overrides));
}

// ---------------------------------------------------------------------------
// SmsQuotaService — monthlyAllowance
// ---------------------------------------------------------------------------

it('uses the config default when the clinic has no override', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => null]);
    config(['platform.sms.monthly_quota' => 500]);

    $service = app(SmsQuotaService::class);

    expect($service->monthlyAllowance($clinic->id))->toBe(500);
});

it('uses the clinic override when one is set', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 200]);
    config(['platform.sms.monthly_quota' => 1000]);

    $service = app(SmsQuotaService::class);

    expect($service->monthlyAllowance($clinic->id))->toBe(200);
});

// ---------------------------------------------------------------------------
// SmsQuotaService — usedThisMonth counting rule
// ---------------------------------------------------------------------------

it('counts Queued rows in the current month toward usage', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'UTC']);
    makeConsumedLog($clinic, ['status' => SmsStatus::Queued->value]);

    expect(app(SmsQuotaService::class)->usedThisMonth($clinic->id))->toBe(1);
});

it('counts Sent rows in the current month toward usage', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'UTC']);
    makeConsumedLog($clinic, ['status' => SmsStatus::Sent->value]);

    expect(app(SmsQuotaService::class)->usedThisMonth($clinic->id))->toBe(1);
});

it('counts Failed rows in the current month toward usage', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'UTC']);
    makeConsumedLog($clinic, ['status' => SmsStatus::Failed->value]);

    expect(app(SmsQuotaService::class)->usedThisMonth($clinic->id))->toBe(1);
});

it('does not count Skipped rows toward usage', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'UTC']);

    makeConsumedLog($clinic, ['status' => SmsStatus::Skipped->value, 'error' => 'disabled by clinic']);
    makeConsumedLog($clinic, ['status' => SmsStatus::Skipped->value, 'error' => 'no phone', 'phone' => '+905309999991']);
    makeConsumedLog($clinic, ['status' => SmsStatus::Skipped->value, 'error' => 'quota exceeded', 'phone' => '+905309999992']);

    expect(app(SmsQuotaService::class)->usedThisMonth($clinic->id))->toBe(0);
});

it('does not count rows from the previous calendar month', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'UTC']);

    $lastMonthStart = Carbon::now('UTC')->subMonth()->startOfMonth()->toDateTimeString();
    makeConsumedLog($clinic, ['status' => SmsStatus::Sent->value, 'created_at' => $lastMonthStart]);

    expect(app(SmsQuotaService::class)->usedThisMonth($clinic->id))->toBe(0);
});

it('does not count OTP rows (clinic_id = null) toward any clinic usage', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'UTC']);

    SmsLog::create([
        'clinic_id' => null,
        'patient_id' => null,
        'phone' => '+905301234567',
        'type' => SmsType::Otp->value,
        'body' => 'OTP: 123456',
        'status' => SmsStatus::Sent->value,
        'error' => null,
        'scheduled_at' => null,
        'sent_at' => now(),
    ]);

    expect(app(SmsQuotaService::class)->usedThisMonth($clinic->id))->toBe(0);
});

// ---------------------------------------------------------------------------
// SmsQuotaService — hasRoom
// ---------------------------------------------------------------------------

it('hasRoom returns true when usage is below the allowance', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 5, 'timezone' => 'UTC']);
    makeConsumedLog($clinic); // 1 of 5

    expect(app(SmsQuotaService::class)->hasRoom($clinic->id))->toBeTrue();
});

it('hasRoom returns false when usage equals the allowance', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 2, 'timezone' => 'UTC']);
    makeConsumedLog($clinic);
    makeConsumedLog($clinic, ['phone' => '+905309999999']);

    expect(app(SmsQuotaService::class)->hasRoom($clinic->id))->toBeFalse();
});

it('hasRoom returns false when usage exceeds the allowance', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 1, 'timezone' => 'UTC']);
    makeConsumedLog($clinic);
    makeConsumedLog($clinic, ['phone' => '+905309999999']); // 2 of 1

    expect(app(SmsQuotaService::class)->hasRoom($clinic->id))->toBeFalse();
});

// ---------------------------------------------------------------------------
// SmsQuotaService — usage() Inertia props contract
// ---------------------------------------------------------------------------

it('usage() returns the correct used / allowance / remaining shape', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 10, 'timezone' => 'UTC']);
    makeConsumedLog($clinic);
    makeConsumedLog($clinic, ['phone' => '+905309999999']);

    $usage = app(SmsQuotaService::class)->usage($clinic->id);

    expect($usage['used'])->toBe(2)
        ->and($usage['allowance'])->toBe(10)
        ->and($usage['remaining'])->toBe(8)
        ->and($usage)->toHaveKey('resets_at');
});

it('usage() remaining is clamped to 0 when over quota', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 1, 'timezone' => 'UTC']);
    makeConsumedLog($clinic);
    makeConsumedLog($clinic, ['phone' => '+905309999999']); // 2 of 1

    $usage = app(SmsQuotaService::class)->usage($clinic->id);

    expect($usage['remaining'])->toBe(0);
});

it('usage() resets_at is an ISO 8601 string for the 1st of next month', function (): void {
    $clinic = Clinic::factory()->create(['timezone' => 'Europe/Istanbul']);

    $usage = app(SmsQuotaService::class)->usage($clinic->id);
    $resetsAt = Carbon::parse($usage['resets_at']);

    expect($resetsAt->day)->toBe(1);
});

// ---------------------------------------------------------------------------
// SmsDispatcher gate — over-quota blocks clinic-scoped sends
// ---------------------------------------------------------------------------

it('over-quota clinic-scoped send is logged Skipped with error=quota exceeded and job is not queued', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 1, 'timezone' => 'UTC']);
    makeConsumedLog($clinic); // quota exhausted

    app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905301234567',
        body: 'Blocked reminder',
        type: SmsType::Reminder24h,
        clinicId: $clinic->id,
    ));

    Queue::assertNothingPushed();

    $skipped = SmsLog::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('status', SmsStatus::Skipped->value)
        ->where('error', 'quota exceeded')
        ->first();

    expect($skipped)->not->toBeNull();
});

it('under-quota clinic-scoped send dispatches SendSmsJob and writes no quota-exceeded skip row', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 5, 'timezone' => 'UTC']);
    makeConsumedLog($clinic); // 1 of 5

    app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905301234567',
        body: 'Booking confirmed',
        type: SmsType::AppointmentCreated,
        clinicId: $clinic->id,
    ));

    Queue::assertPushed(SendSmsJob::class);

    expect(SmsLog::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('error', 'quota exceeded')
        ->exists()
    )->toBeFalse();
});

it('OTP send (clinicId = null) dispatches even when a clinic is over quota', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 1, 'timezone' => 'UTC']);
    makeConsumedLog($clinic); // clinic quota exhausted

    // OTP bypasses the quota gate because clinicId = null.
    app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905301234567',
        body: 'OTP: 654321',
        type: SmsType::Otp,
        clinicId: null,
    ));

    Queue::assertPushed(SendSmsJob::class);
});

// ---------------------------------------------------------------------------
// SmsDispatcher — gate ordering: disabled before quota
// ---------------------------------------------------------------------------

it('a disabled type is Skipped with disabled-by-clinic reason, not quota', function (): void {
    Queue::fake();

    // Zero allowance — quota gate would also block if reached.
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 0, 'timezone' => 'UTC']);

    ClinicSmsSetting::factory()
        ->forType(SmsType::AppointmentCreated)
        ->disabled()
        ->create(['clinic_id' => $clinic->id]);

    app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905301234567',
        body: 'Booking confirmed',
        type: SmsType::AppointmentCreated,
        clinicId: $clinic->id,
    ));

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('status', SmsStatus::Skipped->value)
        ->sole();

    // Stopped at the disabled-gate before reaching quota.
    expect($log->error)->toBe('disabled by clinic');
});

// ---------------------------------------------------------------------------
// SMS settings page — quota Inertia prop contract
// ---------------------------------------------------------------------------

it('GET /clinic/sms-settings includes a quota prop with the correct shape', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 100, 'timezone' => 'UTC']);
    $owner = User::factory()->create();
    quotaRole($owner, 'owner', $clinic->id);

    makeConsumedLog($clinic);
    makeConsumedLog($clinic, ['phone' => '+905309999999']);

    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('sms.quota.used')
            ->has('sms.quota.allowance')
            ->has('sms.quota.remaining')
            ->has('sms.quota.resets_at')
            ->where('sms.quota.used', 2)
            ->where('sms.quota.allowance', 100)
            ->where('sms.quota.remaining', 98)
        );
});

it('GET /clinic/sms-settings quota.remaining is 0 when clinic is at quota', function (): void {
    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 1, 'timezone' => 'UTC']);
    $owner = User::factory()->create();
    quotaRole($owner, 'owner', $clinic->id);

    makeConsumedLog($clinic);

    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sms.quota.remaining', 0));
});

it('GET /clinic/sms-settings quota.allowance uses config default when clinic has no override', function (): void {
    config(['platform.sms.monthly_quota' => 750]);

    $clinic = Clinic::factory()->create(['sms_monthly_quota' => null]);
    $owner = User::factory()->create();
    quotaRole($owner, 'owner', $clinic->id);

    $this->actingAs($owner)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('sms.quota.allowance', 750));
});

// ---------------------------------------------------------------------------
// sendReminder action — quota pre-check toast
// ---------------------------------------------------------------------------

it('sendReminder flashes a warning toast when the clinic is over quota', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 1, 'timezone' => 'UTC']);
    $owner = User::factory()->create();
    quotaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);
    $appointment = quotaAppointment($clinic, $doctor, $patient);

    makeConsumedLog($clinic); // exhaust the 1-SMS quota

    $this->actingAs($owner)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect()
        ->assertSessionHas('toasts');

    // Gate still writes a Skipped-quota row as source of truth.
    expect(SmsLog::withoutGlobalScopes()
        ->where('clinic_id', $clinic->id)
        ->where('error', 'quota exceeded')
        ->where('status', SmsStatus::Skipped->value)
        ->exists()
    )->toBeTrue();
});

it('sendReminder flashes a success toast when the clinic has capacity', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create(['sms_monthly_quota' => 10, 'timezone' => 'UTC']);
    $owner = User::factory()->create();
    quotaRole($owner, 'owner', $clinic->id);
    $doctorUser = User::factory()->create();
    $doctor = Doctor::factory()->create(['clinic_id' => $clinic->id, 'user_id' => $doctorUser->id]);
    $patient = Patient::factory()->create(['clinic_id' => $clinic->id, 'phone' => '+905321234567']);
    $appointment = quotaAppointment($clinic, $doctor, $patient);

    $this->actingAs($owner)
        ->post(route('appointments.send-reminder', $appointment))
        ->assertRedirect()
        ->assertSessionHas('toasts');

    Queue::assertPushed(SendSmsJob::class);
});

// ---------------------------------------------------------------------------
// Multi-tenant isolation (MANDATORY)
// ---------------------------------------------------------------------------

it('clinic A at quota does not block clinic B dispatches', function (): void {
    Queue::fake();

    $clinicA = Clinic::factory()->create(['sms_monthly_quota' => 2, 'timezone' => 'UTC']);
    $clinicB = Clinic::factory()->create(['sms_monthly_quota' => 10, 'timezone' => 'UTC']);

    // Exhaust clinic A.
    makeConsumedLog($clinicA);
    makeConsumedLog($clinicA, ['phone' => '+905309999991']);

    // Clinic B still has room.
    app(SmsDispatcherContract::class)->dispatch(new SmsMessage(
        phone: '+905301234567',
        body: 'Clinic B message',
        type: SmsType::AppointmentCreated,
        clinicId: $clinicB->id,
    ));

    Queue::assertPushed(SendSmsJob::class);

    expect(SmsLog::withoutGlobalScopes()
        ->where('clinic_id', $clinicB->id)
        ->where('error', 'quota exceeded')
        ->exists()
    )->toBeFalse();
});

it('clinic A override does not change clinic B allowance', function (): void {
    $clinicA = Clinic::factory()->create(['sms_monthly_quota' => 50]);
    $clinicB = Clinic::factory()->create(['sms_monthly_quota' => 200]);
    config(['platform.sms.monthly_quota' => 1000]);

    $service = app(SmsQuotaService::class);

    expect($service->monthlyAllowance($clinicA->id))->toBe(50)
        ->and($service->monthlyAllowance($clinicB->id))->toBe(200);
});

it('SMS settings page quota prop shows only clinic A usage, not clinic B usage', function (): void {
    $clinicA = Clinic::factory()->create(['sms_monthly_quota' => 100, 'timezone' => 'UTC']);
    $clinicB = Clinic::factory()->create(['sms_monthly_quota' => 100, 'timezone' => 'UTC']);

    $ownerA = User::factory()->create();
    quotaRole($ownerA, 'owner', $clinicA->id);

    // Clinic A: 3 consumed.
    makeConsumedLog($clinicA);
    makeConsumedLog($clinicA, ['phone' => '+905309999991']);
    makeConsumedLog($clinicA, ['phone' => '+905309999992']);

    // Clinic B: 10 consumed — must not bleed into A's count.
    for ($i = 0; $i < 10; $i++) {
        makeConsumedLog($clinicB, ['phone' => '+9053088888'.str_pad((string) $i, 2, '0', STR_PAD_LEFT)]);
    }

    $this->actingAs($ownerA)
        ->get(route('clinic.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('sms.quota.used', 3)
            ->where('sms.quota.allowance', 100)
            ->where('sms.quota.remaining', 97)
        );
});

// ---------------------------------------------------------------------------
// Arch — cross-module boundary
// ---------------------------------------------------------------------------

it('AppointmentController uses the SmsQuotaContract interface, not the concrete SmsQuotaService', function (): void {
    $source = file_get_contents(app_path('Modules/Scheduling/Http/Controllers/AppointmentController.php'));

    expect($source)->toContain('SmsQuotaContract')
        ->and($source)->not->toContain('SmsQuotaService');
});

it('ClinicSmsSettingService uses the concrete SmsQuotaService (same-module, no contract needed)', function (): void {
    // The quota figure moved with the settings panel when SMS preferences became a clinic tab;
    // both classes live in Messaging, so the concrete service stays a direct dependency.
    $source = file_get_contents(app_path('Modules/Messaging/Services/ClinicSmsSettingService.php'));

    expect($source)->toContain('SmsQuotaService');
});
