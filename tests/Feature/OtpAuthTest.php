<?php

use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\User;
use App\Modules\Messaging\Jobs\SendSmsJob;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/** Valid Turkish phone in local and E.164 formats. */
const OTP_PHONE_LOCAL = '0532 111 22 33';
const OTP_PHONE_E164 = '+905321112233';

beforeEach(function (): void {
    $this->seed(RoleSeeder::class);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Use test-friendly OTP config (short code length, low attempt cap)
    config([
        'platform.otp.length' => 6,
        'platform.otp.ttl' => 300,
        'platform.otp.max_attempts' => 3,
        'platform.otp.resend_throttle' => 60,
    ]);
});

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Seed cache with a known OTP hash so verify tests don't need to dispatch SMS.
 */
function seedOtpCache(string $e164, string $code, int $attempts = 0): void
{
    Cache::put("auth:otp:{$e164}", Hash::make($code), 300);
    Cache::put("auth:otp:attempts:{$e164}", $attempts, 300);
}

/**
 * Create a user with a verified phone number (E.164).
 */
function verifiedPhoneUser(string $e164 = OTP_PHONE_E164): User
{
    return User::factory()->create([
        'phone' => $e164,
        'phone_verified_at' => now(),
    ]);
}

/**
 * Assign a clinic-scoped role (used for redirect assertions in OTP tests).
 */
function otpTestClinicRole(User $user, string $role, int $clinicId): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($clinicId);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
    $user->unsetRelation('permissions');
}

// ---------------------------------------------------------------------------
// OTP request — verified phone
// ---------------------------------------------------------------------------

it('queues exactly one SendSmsJob for a verified phone on OTP request', function (): void {
    Queue::fake();
    verifiedPhoneUser();

    $this->post(route('login.otp.request'), ['phone' => OTP_PHONE_LOCAL])
        ->assertRedirect();

    Queue::assertPushed(SendSmsJob::class, 1);
});

it('queues an SMS with SmsType::Otp and clinicId null for OTP request', function (): void {
    Queue::fake();
    verifiedPhoneUser();

    $this->post(route('login.otp.request'), ['phone' => OTP_PHONE_LOCAL]);

    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job): bool {
        return $job->message->type === SmsType::Otp
            && $job->message->clinicId === null
            && $job->message->phone === OTP_PHONE_E164;
    });
});

it('queues an SMS body that is the resolved translation (not a raw lang key) and contains the generated code', function (): void {
    Queue::fake();
    verifiedPhoneUser();

    $this->post(route('login.otp.request'), ['phone' => OTP_PHONE_LOCAL]);

    Queue::assertPushed(SendSmsJob::class, function (SendSmsJob $job): bool {
        $body = $job->message->body;

        // Must NOT be the raw translation key — catches locale-resolution regression
        expect($body)->not->toBe('auth.otp.sms_body');

        // Brand present (resolved text, any locale).
        expect($body)->toContain('Randevum');

        // The 6-digit code is embedded — extract it without assuming the locale's wording.
        preg_match('/(\d{6})/', $body, $matches);
        $codeInBody = $matches[1] ?? null;
        expect($codeInBody)->not->toBeNull('Code must be extractable from the SMS body');

        // Body is the fully resolved translation (not a raw key), in whatever the active locale is.
        expect($body)->toBe(__('auth.otp.sms_body', [
            'code' => $codeInBody,
            'ttl' => config('platform.otp.ttl'),
        ]));

        $cachedHash = Cache::get('auth:otp:'.OTP_PHONE_E164);
        expect(Hash::check($codeInBody, $cachedHash))->toBeTrue(
            'Code in SMS body must match the hash stored in cache'
        );

        return true;
    });
});

it('stores a hashed OTP in cache after a request for a verified phone', function (): void {
    Queue::fake();
    verifiedPhoneUser();

    $this->post(route('login.otp.request'), ['phone' => OTP_PHONE_LOCAL]);

    expect(Cache::has('auth:otp:'.OTP_PHONE_E164))->toBeTrue();
    expect(Cache::has('auth:otp:attempts:'.OTP_PHONE_E164))->toBeTrue();
});

it('flashes generic otp-sent status regardless of whether the phone exists', function (): void {
    Queue::fake();
    verifiedPhoneUser();

    $this->post(route('login.otp.request'), ['phone' => OTP_PHONE_LOCAL])
        ->assertSessionHas('status', 'otp-sent');
});

// ---------------------------------------------------------------------------
// OTP request — unknown or unverified phone (no enumeration)
// ---------------------------------------------------------------------------

it('queues no jobs for an unknown phone and still returns generic success', function (): void {
    Queue::fake();
    // No user with this phone exists

    $this->post(route('login.otp.request'), ['phone' => OTP_PHONE_LOCAL])
        ->assertSessionHas('status', 'otp-sent');

    Queue::assertNothingPushed();
});

it('queues no jobs for an unverified phone and still returns generic success', function (): void {
    Queue::fake();
    User::factory()->create([
        'phone' => OTP_PHONE_E164,
        'phone_verified_at' => null, // unverified
    ]);

    $this->post(route('login.otp.request'), ['phone' => OTP_PHONE_LOCAL])
        ->assertSessionHas('status', 'otp-sent');

    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------------------
// OTP request validation
// ---------------------------------------------------------------------------

it('rejects an OTP request with a missing phone', function (): void {
    $this->post(route('login.otp.request'), [])
        ->assertSessionHasErrors('phone');
});

it('rejects an OTP request with an invalid phone format', function (): void {
    $this->post(route('login.otp.request'), ['phone' => 'not-a-phone'])
        ->assertSessionHasErrors('phone');
});

// ---------------------------------------------------------------------------
// OTP verify — success
// ---------------------------------------------------------------------------

it('authenticates a user with the correct OTP code', function (): void {
    $user = verifiedPhoneUser();
    $code = '123456';
    seedOtpCache(OTP_PHONE_E164, $code);

    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => $code,
    ])->assertRedirect();

    $this->assertAuthenticatedAs($user);
});

it('redirects a clinic owner to the dashboard after OTP verify', function (): void {
    $clinic = Clinic::factory()->create();
    $user = verifiedPhoneUser();
    otpTestClinicRole($user, 'owner', $clinic->id);

    $code = '123456';
    seedOtpCache(OTP_PHONE_E164, $code);

    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => $code,
    ])->assertRedirect(route('dashboard'));
});

it('redirects a superadmin to the admin route after OTP verify', function (): void {
    $user = verifiedPhoneUser();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->assignRole('superadmin');

    $code = '123456';
    seedOtpCache(OTP_PHONE_E164, $code);

    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => $code,
    ])->assertRedirect(route('admin'));
});

it('consumes the OTP from cache on successful verify', function (): void {
    verifiedPhoneUser();
    $code = '123456';
    seedOtpCache(OTP_PHONE_E164, $code);

    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => $code,
    ]);

    expect(Cache::has('auth:otp:'.OTP_PHONE_E164))->toBeFalse();
    expect(Cache::has('auth:otp:attempts:'.OTP_PHONE_E164))->toBeFalse();
});

it('stamps last_login_at after a successful OTP verify', function (): void {
    $user = verifiedPhoneUser();
    $user->forceFill(['last_login_at' => null])->saveQuietly();

    $code = '123456';
    seedOtpCache(OTP_PHONE_E164, $code);

    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => $code,
    ]);

    expect($user->fresh()->last_login_at)->not->toBeNull();
});

// ---------------------------------------------------------------------------
// OTP verify — failure
// ---------------------------------------------------------------------------

it('returns a code error and stays as guest for a wrong OTP code', function (): void {
    verifiedPhoneUser();
    seedOtpCache(OTP_PHONE_E164, '123456');

    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => '999999',
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('returns a code error and stays as guest when no OTP is cached (expired)', function (): void {
    verifiedPhoneUser();
    // No cache entry — simulates an expired or never-requested OTP

    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => '123456',
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
});

it('enforces the max-attempt cap and blocks verify after too many wrong attempts', function (): void {
    verifiedPhoneUser();
    $correctCode = '123456';
    seedOtpCache(OTP_PHONE_E164, $correctCode, attempts: 0);

    // Exhaust attempts with wrong codes (max_attempts = 3 in beforeEach)
    foreach (range(1, 3) as $_) {
        $this->post(route('login.otp.verify'), [
            'phone' => OTP_PHONE_LOCAL,
            'code' => '000000',
        ]);
    }

    // Even the correct code is now rejected (attempts exceeded)
    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => $correctCode,
    ])->assertSessionHasErrors('code');

    $this->assertGuest();
});

// ---------------------------------------------------------------------------
// OTP verify validation
// ---------------------------------------------------------------------------

it('rejects OTP verify when code is missing', function (): void {
    $this->post(route('login.otp.verify'), ['phone' => OTP_PHONE_LOCAL])
        ->assertSessionHasErrors('code');
});

it('rejects OTP verify when code has wrong digit count', function (): void {
    $this->post(route('login.otp.verify'), [
        'phone' => OTP_PHONE_LOCAL,
        'code' => '123', // too short — must be digits:6
    ])->assertSessionHasErrors('code');
});

it('rejects OTP verify when phone is missing', function (): void {
    $this->post(route('login.otp.verify'), ['code' => '123456'])
        ->assertSessionHasErrors('phone');
});
