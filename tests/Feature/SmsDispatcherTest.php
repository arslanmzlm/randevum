<?php

use App\Enums\SmsStatus;
use App\Enums\SmsType;
use App\Models\Clinic;
use App\Models\ClinicSmsSetting;
use App\Models\SmsLog;
use App\Modules\Messaging\Contracts\SmsDispatcherContract;
use App\Modules\Messaging\Data\SmsMessage;
use App\Modules\Messaging\Jobs\SendSmsJob;
use App\Modules\Messaging\Services\SmsDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Build a clinic-scoped SmsMessage.
 */
function cliMsg(int $clinicId, SmsType $type = SmsType::AppointmentCreated): SmsMessage
{
    return new SmsMessage(
        phone: '+905321112233',
        body: 'Test body',
        type: $type,
        clinicId: $clinicId,
    );
}

// ---------------------------------------------------------------------------
// Platform / OTP bypass (clinicId === null)
// ---------------------------------------------------------------------------

it('dispatches SendSmsJob when clinicId is null (OTP / platform send)', function (): void {
    Queue::fake();

    $message = new SmsMessage(
        phone: '+905321112233',
        body: 'OTP kodu: 1234',
        type: SmsType::Otp,
        clinicId: null,
    );

    app(SmsDispatcherContract::class)->dispatch($message);

    Queue::assertPushed(SendSmsJob::class);
    // SendSmsJob opens its sms_logs row in the constructor (dispatch-time), which
    // runs even under Queue::fake() — a queued send is visible immediately.
    expect(SmsLog::withoutGlobalScopes()->count())->toBe(1)
        ->and(SmsLog::withoutGlobalScopes()->sole()->status)->toBe(SmsStatus::Queued);
});

it('dispatches SendSmsJob for Otp type even when clinicId is set (belt-and-suspenders)', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();

    $message = new SmsMessage(
        phone: '+905321112233',
        body: 'OTP kodu: 5678',
        type: SmsType::Otp,
        clinicId: $clinic->id,
    );

    app(SmsDispatcherContract::class)->dispatch($message);

    Queue::assertPushed(SendSmsJob::class);
    // Same as above: the constructor-created row exists regardless of Queue::fake().
    expect(SmsLog::withoutGlobalScopes()->count())->toBe(1)
        ->and(SmsLog::withoutGlobalScopes()->sole()->status)->toBe(SmsStatus::Queued);
});

// ---------------------------------------------------------------------------
// Gate: enabled (missing row = default ON)
// ---------------------------------------------------------------------------

it('dispatches SendSmsJob when no preference row exists (missing row = enabled)', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();

    // No ClinicSmsSetting rows — default ON for all types.
    app(SmsDispatcherContract::class)->dispatch(cliMsg($clinic->id, SmsType::AppointmentCreated));

    Queue::assertPushed(SendSmsJob::class);
    expect(SmsLog::withoutGlobalScopes()->count())->toBe(1)
        ->and(SmsLog::withoutGlobalScopes()->sole()->status)->toBe(SmsStatus::Queued);
});

it('dispatches SendSmsJob when the type is explicitly enabled', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    ClinicSmsSetting::factory()->forType(SmsType::Reminder24h)->create([
        'clinic_id' => $clinic->id,
        'enabled' => true,
    ]);

    app(SmsDispatcherContract::class)->dispatch(cliMsg($clinic->id, SmsType::Reminder24h));

    Queue::assertPushed(SendSmsJob::class);
    expect(SmsLog::withoutGlobalScopes()->where('status', SmsStatus::Skipped)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Gate: disabled → Skipped log, nothing dispatched
// ---------------------------------------------------------------------------

it('writes Skipped log and dispatches nothing when the type is disabled', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCreated)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    app(SmsDispatcherContract::class)->dispatch(cliMsg($clinic->id, SmsType::AppointmentCreated));

    Queue::assertNothingPushed();

    $log = SmsLog::withoutGlobalScopes()->sole();
    expect($log->status)->toBe(SmsStatus::Skipped)
        ->and($log->error)->toBe('disabled by clinic')
        ->and($log->clinic_id)->toBe($clinic->id)
        ->and($log->phone)->toBe('+905321112233')
        ->and($log->body)->toBe('Test body')
        ->and($log->type)->toBe(SmsType::AppointmentCreated);
});

it('writes exactly one Skipped log row when disabled (no extra rows)', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    ClinicSmsSetting::factory()->forType(SmsType::BalanceReminder)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    app(SmsDispatcherContract::class)->dispatch(cliMsg($clinic->id, SmsType::BalanceReminder));

    expect(SmsLog::withoutGlobalScopes()->count())->toBe(1);
});

it('dispatches when only an unrelated type is disabled', function (): void {
    Queue::fake();

    $clinic = Clinic::factory()->create();
    // Disable a DIFFERENT type (AppointmentCancelled).
    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCancelled)->disabled()->create([
        'clinic_id' => $clinic->id,
    ]);

    // Sending AppointmentCreated — should still go through.
    app(SmsDispatcherContract::class)->dispatch(cliMsg($clinic->id, SmsType::AppointmentCreated));

    Queue::assertPushed(SendSmsJob::class);
});

// ---------------------------------------------------------------------------
// Gate reads the correct clinic's settings (cross-clinic safety)
// ---------------------------------------------------------------------------

it("gate uses only the sending clinic's settings, not another clinic's", function (): void {
    Queue::fake();

    $clinicA = Clinic::factory()->create();
    $clinicB = Clinic::factory()->create();

    // Clinic B disables AppointmentCreated.
    ClinicSmsSetting::factory()->forType(SmsType::AppointmentCreated)->disabled()->create([
        'clinic_id' => $clinicB->id,
    ]);

    // Clinic A has no preference row — defaults ON.
    app(SmsDispatcherContract::class)->dispatch(cliMsg($clinicA->id, SmsType::AppointmentCreated));

    // Clinic A's message should be dispatched (not blocked by clinic B's setting).
    Queue::assertPushed(SendSmsJob::class);
    expect(SmsLog::withoutGlobalScopes()->where('status', SmsStatus::Skipped)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// Contract binding
// ---------------------------------------------------------------------------

it('resolves SmsDispatcherContract to the SmsDispatcher concrete', function (): void {
    expect(app(SmsDispatcherContract::class))->toBeInstanceOf(SmsDispatcher::class);
});
